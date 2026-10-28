<?php

namespace Porro\Laravel\Activity;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Telescope\Telescope;
use Porro\Core\Activity\AbuseIpdb;
use Porro\Core\Activity\Blocklist;
use Porro\Core\Activity\Buffer;
use Porro\Core\Activity\Cidr;
use Porro\Core\Activity\ClientIp;
use Porro\Core\Activity\CloudflareError;
use Porro\Core\Activity\Countries;
use Porro\Core\Activity\Entry;
use Porro\Core\Activity\FlaggedIp;
use Porro\Core\Activity\IpActivity;
use Porro\Core\Activity\IpDetails;
use Porro\Core\Activity\IpSet;
use Porro\Core\Activity\Probes;
use Porro\Core\Activity\ProtectedRanges;
use Porro\Core\Activity\Ranking;
use Porro\Core\Activity\Recorder;
use Porro\Core\Activity\Summary;
use Porro\Core\Health\Issue;
use Porro\Core\Health\Sources;
use Porro\Laravel\HttpSources;
use Throwable;

/**
 * Suspicious activity and blocking for this site, on core's Activity.
 *
 * On a visitor's request this only ever reads the cache: the blocklist is
 * compiled there, and protected ranges come from the last fetch (or the
 * bundled copy). The network and the database are touched by the
 * scheduled sync and the control panel, never by a page view. A
 * suspicious request appends a line to this server's buffer file, and
 * flush() records the lines in batches.
 *
 * A local or staging copy (anything but production) enforces blocks but
 * doesn't record activity unless asked to, and never writes the nginx
 * file: it often has the live database, and its own traffic is noise.
 */
class Activity
{
    public const COMPILED = 'porro:blocklist:compiled';

    public const COMPILED_TTL = 300;

    /** Porro's own cache store and table, which a deploy's cache:clear leaves alone. */
    public const STORE = 'porro';

    public const TABLE = 'porro_cache';

    /** Kept across deploys, so copied over from the app's cache once. */
    private const CARRIED = [CloudflareMode::STATE, NginxServers::KEY, self::BEHIND_CLOUDFLARE];

    /** Accounts above this are too many to match hashes against. */
    private const USERS_LIMIT = 5000;

    private ?ProtectedRanges $ranges = null;

    private ?Recorder $recorder = null;

    private ?Probes $probes = null;

    private bool $syncQueued = false;

    /** One flush in this many suspicious requests, after the response. */
    public const FLUSH_ODDS = 50;

    /** Something was buffered this request, so it may flush once it's over. */
    private bool $buffered = false;

    public function __construct(private Application $app) {}

    /**
     * Where activity, the Cloudflare connection and nginx check-ins live:
     * PORRO_ACTIVITY_STORE, or Porro's own table. Until its migration has
     * run, the app's cache, so recording never fails on a missing table.
     */
    public static function store(): Repository
    {
        $name = (string) (config('porro.activity.store') ?: self::STORE);

        if ($name === self::STORE && ! self::ready()) {
            return Cache::store();
        }

        return Cache::store($name);
    }

    /** Once the table is there, it stays: checked once a process after that. */
    private static bool $ready = false;

    private static function ready(): bool
    {
        return self::$ready = self::$ready || (bool) Cache::remember('porro:cache:ready', 600, fn () => Schema::hasTable(self::TABLE));
    }

    /**
     * Called when the app boots, so a long-running worker or a test run
     * checks the table again.
     */
    public static function forgetStore(): void
    {
        self::$ready = false;
    }

    /** @var array{blocks: IpSet, safe: IpSet, watch: IpSet}|null read once a request, or once a minute on a long-running worker */
    private ?array $compiled = null;

    private int $compiledAt = 0;

    public function isProduction(): bool
    {
        return $this->app->environment('production', 'prod', 'live');
    }

    /** Set when someone opens the nginx setup page through Cloudflare. */
    public const BEHIND_CLOUDFLARE = 'porro:activity:behind-cloudflare';

    /**
     * Whether the nginx file carries Cloudflare's real IP lines:
     * PORRO_NGINX_REAL_IP when set, otherwise when the site is behind
     * Cloudflare (a Cloudflare token, or the setup page seen through it).
     */
    public function nginxRealIp(): bool
    {
        $setting = config('porro.blocklist.nginx_real_ip');

        if ($setting !== null && $setting !== '') {
            return filter_var($setting, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) config('porro.blocklist.cloudflare_token') || (bool) self::store()->get(self::BEHIND_CLOUDFLARE);
    }

    /**
     * Whether requests are recorded: PORRO_ACTIVITY when set, otherwise
     * only in production.
     */
    public function recording(): bool
    {
        $setting = config('porro.activity.enabled');

        return $setting === null ? $this->isProduction() : filter_var($setting, FILTER_VALIDATE_BOOLEAN);
    }

    public function enforcing(): bool
    {
        return (bool) config('porro.blocklist.app', true);
    }

    /**
     * Protected ranges from the cache only. The sync fetches them.
     */
    /**
     * Protected lists switched off in porro.blocklist.protect.
     *
     * @return list<string>
     */
    public function switchedOff(): array
    {
        $protect = (array) config('porro.blocklist.protect', []);

        return array_values(array_filter(ProtectedRanges::OPTIONAL, fn (string $source) => array_key_exists($source, $protect) && ! $protect[$source]));
    }

    public function ranges(): ProtectedRanges
    {
        return $this->ranges ??= new ProtectedRanges($this->cachedSources(), $this->strings('porro.blocklist.protected'), $this->switchedOff());
    }

    /**
     * Protected ranges fetched now when the cached copy is old.
     */
    public function freshRanges(): ProtectedRanges
    {
        return $this->ranges = new ProtectedRanges(HttpSources::make(), $this->strings('porro.blocklist.protected'));
    }

    public function blocklist(): Blocklist
    {
        return new Blocklist(
            new DatabaseEntryStore('porro_blocklist'),
            new DatabaseEntryStore('porro_safelist'),
            $this->ranges(),
            (array) config('porro.blocklist.safelist', []),
        );
    }

    public function recorder(): Recorder
    {
        return $this->recorder ??= new Recorder(
            fn (string $key) => self::store()->get($key),
            fn (string $key, mixed $value, int $ttl) => self::store()->put($key, $value, $ttl),
            function (string $key, int $ttl, int $by = 1) {
                $store = self::store();

                // A new key is one insert; only an existing one needs increment().
                if (! $store->add($key, $by, $ttl)) {
                    $store->increment($key, $by);
                }
            },
            $this->ranges(),
            (string) config('app.key'),
            fn (string $ip) => $this->compiled()['safe']->has($ip),
            (array) config('porro.activity.thresholds', []),
            fn (array $keys) => self::store()->many($keys),
            buffer: $this->buffer(),
            lock: function (Closure $work) {
                $store = self::store()->getStore();

                // Flushes from other servers wait their turn, or skip this one.
                return $store instanceof LockProvider ? $store->lock('porro:activity:flush', 120)->get($work) : $work();
            },
        );
    }

    /**
     * This server's buffer file: PORRO_ACTIVITY_BUFFER, or storage/porro.
     * Set it to false to record every request straight away.
     */
    public function buffer(): ?Buffer
    {
        $path = config('porro.activity.buffer');

        if ($path === false || $path === 'false' || $path === '0') {
            return null;
        }

        return new Buffer(is_string($path) && $path !== '' ? $path : storage_path('porro/activity.buf'));
    }

    /**
     * Records what this server's buffer holds. Pages that read activity
     * call it first; with nothing waiting it's a lock and a stat.
     */
    public function flush(): void
    {
        $this->recorder()->flush();
    }

    /**
     * Called once a request has buffered something: no cost to anyone else.
     */
    public function buffered(): void
    {
        $this->buffered = true;
    }

    /**
     * After the response: flush now and then, or when this server hasn't
     * for a minute, so flags and alerts lag a minute at most.
     */
    public function flushIfDue(): void
    {
        if (! $this->buffered) {
            return;
        }

        $this->buffered = false;

        if (random_int(1, max(1, (int) config('porro.activity.flush_odds', self::FLUSH_ODDS))) === 1 || $this->recorder()->flushDue()) {
            $this->recorder()->flush();
        }
    }

    public function probes(): Probes
    {
        $served = $this->strings('porro.activity.served');

        if (class_exists(Telescope::class)) {
            $served[] = '/'.trim((string) config('telescope.path', 'telescope'), '/');
        }

        return $this->probes ??= new Probes('laravel', $served);
    }

    public function clientIp(Request $request): string
    {
        return ClientIp::resolve((string) $request->ip(), $request->headers->all(), $this->ranges());
    }

    /**
     * True when the request reached the server through Cloudflare, false
     * when it came straight to the server's own address.
     */
    public function viaCloudflare(Request $request): bool
    {
        return $this->ranges()->isCloudflare((string) $request->server('REMOTE_ADDR'));
    }

    /**
     * Whether $ip was flagged in the last 48 hours, so its every request
     * is counted for its page. Read with the blocklist: no extra lookup.
     */
    public function watching(string $ip): bool
    {
        return $this->compiled()['watch']->has($ip);
    }

    /**
     * The visitor's country when Cloudflare sent it: free, no lookup.
     */
    public function country(Request $request): ?string
    {
        return ClientIp::country((string) $request->ip(), $request->headers->all(), $this->ranges());
    }

    /**
     * DB-IP's country file in storage, for IPs Cloudflare didn't place.
     */
    public function countries(): Countries
    {
        return new Countries(storage_path('porro'));
    }

    /**
     * Who's behind $ip: Cloudflare (when it's set up), DB-IP, the Tor and
     * Spamhaus lists, and confirmed reverse DNS. Cached a day per IP.
     *
     * @return array<string, mixed>
     */
    public function details(string $ip, ?string $userAgent = null): array
    {
        // Bound in tests, so they never wait on real DNS.
        $dns = app()->bound('porro.reverse-dns') ? app('porro.reverse-dns') : null;

        return (new IpDetails(HttpSources::make(), $this->countries(), $this->network(...), $dns))->lookup($ip, $userAgent);
    }

    /**
     * AbuseIPDB's view of $ip, when PORRO_ABUSEIPDB_KEY is set. This one
     * sends the IP to AbuseIPDB, so only call it when someone asks.
     *
     * @return array<string, mixed>|null
     */
    public function reputation(string $ip): ?array
    {
        return (new AbuseIpdb(HttpSources::make(), (string) config('porro.activity.abuseipdb_key')))->check($ip);
    }

    /**
     * Cloudflare's view of an IP's network. Intel needs a permission most
     * tokens won't have; once it's refused, Radar for a day.
     *
     * @return array<string, mixed>|null
     */
    private function network(string $ip): ?array
    {
        $cloudflare = app(CloudflareMode::class);

        if (! $cloudflare->configured()) {
            return null;
        }

        $account = $cloudflare->state()['connection']['account'] ?? null;

        if ($account && ! Cache::get('porro:cloudflare:no-intel')) {
            try {
                return $cloudflare->client()->intel((string) $account, $ip) + ['source' => 'Cloudflare IP Intelligence'];
            } catch (CloudflareError $e) {
                if (in_array($e->status, [401, 403], true)) {
                    Cache::put('porro:cloudflare:no-intel', true, 86400);
                }
            }
        }

        return $cloudflare->client()->radar($ip) + ['network_type' => null, 'risks' => [], 'source' => 'Cloudflare Radar'];
    }

    /**
     * Why $ip is blocked, or null. Protected ranges and the safelist win.
     */
    public function blocked(string $ip): ?string
    {
        $compiled = $this->compiled();

        if ($compiled['safe']->has($ip) || $this->ranges()->match($ip) !== null) {
            return null;
        }

        return $compiled['blocks']->match($ip);
    }

    /**
     * Housekeeping on the scheduled task: fetch protected ranges (so
     * requests never wait on them), drop expired and now-protected
     * entries, and keep the country file current. Never throws.
     *
     * @return list<array{list: string, cidr: string, why: string}> what was removed
     */
    public function maintain(): array
    {
        try {
            $this->recorder()->flush();

            if (! Schema::hasTable('porro_blocklist')) {
                return [];
            }

            $this->freshRanges()->ranges();
            $removed = $this->blocklist()->prune();

            foreach ($removed as $entry) {
                Log::info("Porro: removed {$entry['cidr']} from the {$entry['list']}: {$entry['why']}");
            }

            $this->forgetCompiled();
            $this->tidyStore();
            app(CloudflareMode::class)->sync();

            // A few checks a day at most, so a failed download isn't retried every run.
            if ($this->recording() && Cache::add('porro:countries:checked', true, 6 * 3600)) {
                $this->countries()->update();
            }

            return $removed;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Porro's table keeps expired rows until something reads them, so
     * clear them hourly: activity is kept 48 hours, not until the next
     * visit. Also carries state saved in the app's cache by older
     * versions across, once.
     */
    public function tidyStore(): void
    {
        if ((config('porro.activity.store') ?: self::STORE) !== self::STORE || ! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (self::CARRIED as $key) {
            if (($value = Cache::get($key)) !== null && self::store()->get($key) === null) {
                self::store()->forever($key, $value);
                Cache::forget($key);
            }
        }

        if (Cache::add('porro:cache:pruned', true, 3600)) {
            DB::table(self::TABLE)->where('expiration', '<=', time())->delete();
            DB::table(self::TABLE.'_locks')->where('expiration', '<=', time())->delete();
        }
    }

    /**
     * Call after any change to either list.
     */
    private function forgetCompiled(): void
    {
        Cache::forget(self::COMPILED);
        $this->compiled = null;
    }

    public function changed(): void
    {
        $this->forgetCompiled();

        // Cloudflare after the response, so whoever blocked doesn't wait.
        if (! $this->syncQueued && app(CloudflareMode::class)->configured()) {
            $this->syncQueued = true;
            app()->terminating(function () {
                $this->syncQueued = false;
                app(CloudflareMode::class)->sync();
            });
        }
    }

    public function summary(int $hours = 24): Summary
    {
        $this->flush();

        return $this->recorder()->summary($hours);
    }

    /**
     * Everything one IP did in the last 48 hours, buffered requests included.
     */
    public function history(string $ip): ?IpActivity
    {
        $this->flush();

        return $this->recorder()->history($ip);
    }

    /**
     * The summary's flagged IPs with a country for each, where known.
     *
     * @return list<FlaggedIp>
     */
    public function flagged(Summary $summary): array
    {
        // What's known without waiting: the Tor and Spamhaus lists, and any
        // lookup already cached from someone opening an IP's details.
        $details = new IpDetails(HttpSources::make(), null, null, fn () => null);

        return Ranking::apply(
            $this->countries()->fill($summary->flagged),
            function ($ip) use ($details) {
                try {
                    return $details->known($ip->ip, $ip->userAgent);
                } catch (Throwable) {
                    return ['badges' => [], 'risks' => [], 'network_type' => null];
                }
            },
        );
    }

    /**
     * The summary's issues, plus one for each account tried from many IPs,
     * named when it matches one of the app's users.
     *
     * @return list<Issue>
     */
    public function issues(Summary $summary): array
    {
        $issues = $summary->issues();
        $names = null;

        foreach ($summary->targets as $hash => $ips) {
            if ($ips < (int) config('porro.activity.thresholds.login_users', 5)) {
                continue;
            }

            $names ??= $this->accountNames();
            $who = $names[$hash] ?? 'An account';

            $issues[] = new Issue('suspicious_activity', Issue::WARNING, "{$who} targeted from {$ips} IPs", 'Failed logins for the same account from many addresses · looks like password spraying · check it has two-factor sign-in');
        }

        return $issues;
    }

    /**
     * @return array{blocks: IpSet, safe: IpSet, watch: IpSet}
     */
    private function compiled(): array
    {
        if ($this->compiled !== null && time() - $this->compiledAt < 60) {
            return $this->compiled;
        }

        try {
            $data = Cache::remember(self::COMPILED, self::COMPILED_TTL, function () {
                $list = $this->blocklist();

                return [
                    'blocks' => array_map(fn (Entry $e) => [$e->cidr, $e->note], $list->blocks()),
                    'safe' => array_map(fn (Entry $e) => $e->cidr, $list->safe()),
                    'watch' => $this->recorder()->watched(),
                ];
            });
        } catch (Throwable) {
            // No table yet, or no database: nothing is blocked.
            $data = ['blocks' => [], 'safe' => [], 'watch' => []];
        }

        $blocks = new IpSet;

        foreach ($data['blocks'] ?? [] as [$cidr, $note]) {
            $blocks->add(Cidr::tryParse((string) $cidr), (string) $note);
        }

        $this->compiledAt = time();

        return $this->compiled = ['blocks' => $blocks, 'safe' => IpSet::of($data['safe'] ?? []), 'watch' => IpSet::of($data['watch'] ?? [])];
    }

    /**
     * Username hash => email, for the app's users, when there aren't too many.
     *
     * @return array<string, string>
     */
    private function accountNames(): array
    {
        try {
            $model = config('auth.providers.users.model');

            if (! is_string($model) || ! class_exists($model) || $model::query()->count() > self::USERS_LIMIT) {
                return [];
            }

            $names = [];

            foreach ($model::query()->pluck('email') as $email) {
                $names[$this->recorder()->userHash((string) $email)] = (string) $email;
            }

            return $names;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Sources that only read the cache, for request time: a miss falls
     * back to the bundled lists instead of waiting on the network.
     */
    private function cachedSources(): Sources
    {
        return new Sources(
            fn (string $key) => Cache::get($key),
            fn () => null,
            fn () => [0, ''],
        );
    }

    /**
     * @return list<string>
     */
    private function strings(string $key): array
    {
        return array_values(array_filter(array_map('strval', (array) config($key, []))));
    }
}
