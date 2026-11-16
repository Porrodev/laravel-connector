<?php

namespace Porro\Laravel\Connect;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Porro\Core\ConnectClient;
use Porro\Core\ConnectError;
use Porro\Core\ConnectResult;
use Porro\Core\Cron;
use Porro\Core\CrontabIo;
use Porro\Core\Report;
use Porro\Core\SiteKey;
use Porro\Laravel\Activity\Activity;
use Porro\Laravel\Reporter;
use Porro\Laravel\Reporting;
use Porro\Laravel\SiteHealth;
use Porro\Laravel\SiteKeys;
use Statamic\Statamic;
use Throwable;

/**
 * "Connect to Porro" from the site's own control panel, with no key to copy.
 *
 * start(): make a key pair here, ask Porro to connect, and hand back the
 * page on Porro to send the browser to. finish(): the browser is back with
 * a grant; finish it with Porro, keep the key (SiteKeys), send the first
 * report. The key only becomes the site's when Porro accepts the grant.
 *
 * The request waits for about ten minutes, encrypted, in Porro's own
 * store. It can only be finished from the browser session that started it,
 * by the same signed-in person: the session holds a state value the
 * waiting request is filed under, so a grant for anyone else's request
 * finds nothing to finish.
 *
 * The control panel (the /porro page, Statamic, Filament) owns the routes,
 * the permission check and how a ConnectFailure is shown.
 */
class PanelConnect
{
    /** PORRO_KEY is set: nothing to connect here. */
    public const ENV = 'env';

    /** Not the live site: no button, developer copy instead. */
    public const DEVELOPMENT = 'development';

    /** Connected with a key made here. */
    public const CONNECTED = 'connected';

    /** PORRO_ENABLED=false on the live site. */
    public const OFF = 'off';

    /** The live site, not connected: show the button. */
    public const READY = 'ready';

    public const MINUTES = 10;

    private const SESSION = 'porro.connect';

    private const DONE = 'porro.connected';

    public function __construct(private Application $app, private SiteKeys $keys, private Reporter $reporter) {}

    /**
     * What the panel should show.
     */
    public function state(): string
    {
        return match (true) {
            $this->keys->fromEnv() !== null => self::ENV,
            ! Reporting::production() => self::DEVELOPMENT,
            $this->keys->stored() !== null => self::CONNECTED,
            Reporting::offReason() !== null => self::OFF,
            default => self::READY,
        };
    }

    /**
     * Whether the browser is coming back from Porro with a grant.
     */
    public function returning(Request $request): bool
    {
        return is_string($request->query('porro_request')) && $request->query('porro_request') !== '';
    }

    /**
     * Ask Porro to connect this site.
     *
     * @param  string  $returnPath  the panel's return route, as a path ("/porro/connect")
     * @param  string  $user  whoever is signed in to the panel
     * @return string the page on Porro to send the browser to
     *
     * @throws ConnectFailure
     */
    public function start(Request $request, string $returnPath, string $user): string
    {
        $this->guard($request);

        // The address the browser is on: the grant comes back to this host.
        $address = rtrim($request->root(), '/');
        $key = SiteKey::generate();

        try {
            $pending = $this->client()->start($key, $address, $address.'/'.ltrim($returnPath, '/'), ...$this->describe());
        } catch (ConnectError $e) {
            throw ConnectFailure::from($e);
        }

        $state = Str::random(40);

        Activity::store()->put($this->pendingKey($state), SiteKeys::seal((string) json_encode([
            'seed' => $key->seed(),
            'id' => $pending->id,
            'user' => $user,
        ])), self::MINUTES * 60);

        $request->session()->put(self::SESSION, ['state' => $state, 'id' => $pending->id]);

        return $pending->approveUrl;
    }

    /**
     * Finish it with the grant the browser brought back.
     *
     * @return string|null the page on Porro to send the browser on to, when there is one
     *
     * @throws ConnectFailure
     */
    public function finish(Request $request, string $user): ?string
    {
        $id = (string) $request->query('porro_request');
        $grant = $request->query('porro_grant');
        $next = $request->query('porro_next');
        $session = $request->session();

        // The same link opened again once it's done: nothing left to do.
        if ($session->get(self::DONE) === $id && $this->keys->stored() !== null) {
            return $this->next($next);
        }

        $started = $session->get(self::SESSION);

        if (! is_array($started) || ! is_string($started['state'] ?? null) || ! is_string($started['id'] ?? null)) {
            throw new ConnectFailure(ConnectFailure::EXPIRED, 'There’s no request to connect waiting in this browser session. It may have run out of time. Press Connect to Porro again.');
        }

        // Only ever the request this session started, by the same person.
        $pending = $this->pending($started['state']);

        if (! hash_equals($started['id'], $id) || ($pending !== null && (! hash_equals($pending['id'], $id) || ! hash_equals($pending['user'], $user)))) {
            throw new ConnectFailure(ConnectFailure::MISMATCH, 'That approval is for a different request from the one started here, so nothing was connected. Press Connect to Porro again.');
        }

        if ($pending === null) {
            $session->forget(self::SESSION);

            throw new ConnectFailure(ConnectFailure::EXPIRED, 'This request ran out of time. Press Connect to Porro again and approve it within ten minutes.');
        }

        if (! is_string($grant) || $grant === '') {
            throw new ConnectFailure(ConnectFailure::MISMATCH, 'Porro sent the browser back without an approval, so nothing was connected. Press Connect to Porro again.');
        }

        $this->guard($request, returning: true);

        try {
            $result = $this->client()->complete(SiteKey::fromSeed($pending['seed']), $id, $grant);
        } catch (ConnectError $e) {
            // Porro couldn't be reached: the grant still works, so opening
            // the link again tries again. Anything else is final.
            if (! in_array($e->reason, [ConnectError::UNREACHABLE, ConnectError::RATE_LIMITED], true)) {
                $this->clear($request, $started['state']);
            }

            throw ConnectFailure::from($e);
        }

        if (! $this->keep($result)) {
            throw new ConnectFailure(ConnectFailure::NOT_MIGRATED, 'Porro approved this site, but the key couldn’t be saved in this site’s database, so it isn’t connected yet. Once the database is working, press Connect to Porro again.');
        }

        $this->clear($request, $started['state']);
        $session->put(self::DONE, $id);
        $this->firstReport($result->key);

        return $this->next($next);
    }

    /**
     * Porro has made the key the site's, and that can't be asked for
     * twice: a database that blinks gets a few goes at saving it.
     */
    private function keep(ConnectResult $result): bool
    {
        foreach ([0, 200_000, 600_000] as $wait) {
            usleep($wait);

            try {
                $this->keys->store($result->key, $result->workspace, $result->host);

                return true;
            } catch (Throwable) {
                // Try again; never the error's text, which could quote the row.
            }
        }

        return false;
    }

    /**
     * @throws ConnectFailure
     */
    private function guard(Request $request, bool $returning = false): void
    {
        if ($this->keys->fromEnv() !== null) {
            throw new ConnectFailure(ConnectFailure::ENV_KEY, 'This site already has a site key in its environment (PORRO_KEY), and that key is the one it uses. Remove it first if you want to connect from here instead.');
        }

        if (! Reporting::production()) {
            throw new ConnectFailure(ConnectFailure::NOT_PRODUCTION, 'This copy’s environment is “'.$this->app->environment().'”, so it doesn’t connect and sends no reports. Connect from the live site’s control panel.');
        }

        if (Reporting::offReason() !== null) {
            throw new ConnectFailure(ConnectFailure::OFF, 'Reporting to Porro is switched off on this site (PORRO_ENABLED=false). Remove that setting, then connect.');
        }

        if (! $this->keys->ready()) {
            throw new ConnectFailure(ConnectFailure::NOT_MIGRATED, 'The connector’s database tables aren’t there yet. Run the migrations ('.$this->cli().' migrate), then press Connect to Porro again.');
        }

        if (! $returning && self::behindUntrustedProxy($request)) {
            throw new ConnectFailure(ConnectFailure::SCHEME, 'Your browser is on https, but this site sees the request as plain http. That usually means it’s behind a load balancer or proxy it doesn’t trust yet, so Porro would be given the wrong address. Trust the proxy in the app (Laravel’s trustProxies setting), then press Connect to Porro again.');
        }
    }

    /**
     * The browser says https and the app says http: a proxy in front ends
     * TLS and the app doesn't trust its forwarded headers. Asking Porro to
     * connect "http://…" would be the wrong site address.
     */
    public static function behindUntrustedProxy(Request $request): bool
    {
        if ($request->isSecure()) {
            return false;
        }

        $host = strtolower($request->getHost());

        foreach (['Origin', 'Referer'] as $header) {
            $from = parse_url((string) $request->headers->get($header));

            if (is_array($from) && strtolower($from['scheme'] ?? '') === 'https' && strtolower($from['host'] ?? '') === $host) {
                return true;
            }
        }

        $appUrl = parse_url((string) config('app.url'));

        return stripos((string) $request->headers->get('X-Forwarded-Proto'), 'https') !== false
            || strtolower((string) $request->headers->get('X-Forwarded-Ssl')) === 'on'
            || strtolower((string) $request->headers->get('Front-End-Https')) === 'on'
            || stripos((string) $request->headers->get('CF-Visitor'), '"https"') !== false
            || (is_array($appUrl) && strtolower($appUrl['scheme'] ?? '') === 'https' && strtolower($appUrl['host'] ?? '') === $host);
    }

    /**
     * What the approval page shows about this site: the connector, what it
     * runs and its environment, as a report would say them.
     *
     * @return array{0: array{name: string, version: string}, 1: array{name: string|null, version: string|null, environment: string|null}, 2: string}
     */
    private function describe(): array
    {
        $name = $this->reporter->connectorName();
        $connector = ['name' => $name, 'version' => Report::installedVersion($name) ?? 'dev'];

        try {
            $report = $this->reporter->build()->toArray();

            // What the site is to the people who run it: Statamic, say,
            // rather than the Laravel underneath.
            $runs = $report['layers'][0] ?? $report['platform'];

            return [$connector, [
                'name' => $runs['name'] ?? 'laravel',
                'version' => $runs['version'] ?? null,
                'environment' => $report['platform']['environment'] ?? $this->app->environment(),
            ], (string) ($report['php']['version'] ?? PHP_VERSION)];
        } catch (Throwable) {
            return [$connector, ['name' => 'laravel', 'version' => $this->app->version(), 'environment' => $this->app->environment()], PHP_VERSION];
        }
    }

    /**
     * The first report, as `porro:connect` sends it. The site is connected
     * whether or not it gets through: the scheduler sends the next one.
     */
    private function firstReport(SiteKey $key): void
    {
        try {
            $this->app->make(SiteHealth::class)->forget();

            $scheduled = Cron::isScheduled($this->app->basePath(), 'artisan schedule:run', $this->app->make(CrontabIo::class));

            $this->reporter->send($key->token(), withPackages: true, trigger: Report::TRIGGER_CONNECT, health: ['scheduler_in_crontab' => $scheduled]);
        } catch (Throwable) {
            // Never the key or the error's text in a log.
        }
    }

    /**
     * Only ever on to the Porro this site is set up for.
     */
    private function next(mixed $next): ?string
    {
        return is_string($next) && $this->client()->onPorro($next) ? $next : null;
    }

    /**
     * @return array{seed: string, id: string, user: string}|null
     */
    private function pending(string $state): ?array
    {
        try {
            $value = Activity::store()->get($this->pendingKey($state));
            $data = is_string($value) ? json_decode(Crypt::decryptString($value), true) : null;
        } catch (Throwable) {
            return null;
        }

        return is_array($data) && is_string($data['seed'] ?? null) && is_string($data['id'] ?? null) && is_string($data['user'] ?? null)
            ? ['seed' => $data['seed'], 'id' => $data['id'], 'user' => $data['user']]
            : null;
    }

    private function clear(Request $request, string $state): void
    {
        Activity::store()->forget($this->pendingKey($state));
        $request->session()->forget(self::SESSION);
    }

    private function pendingKey(string $state): string
    {
        return 'porro:connect:'.hash('sha256', $state);
    }

    private function client(): ConnectClient
    {
        return new ConnectClient((string) config('porro.url'), $this->reporter->transport());
    }

    private function cli(): string
    {
        return class_exists(Statamic::class) ? 'php please' : 'php artisan';
    }
}
