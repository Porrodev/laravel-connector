<?php

namespace Porro\Laravel\Activity;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Telescope\Telescope;
use Porro\Core\Activity\AbuseIpdb;
use Porro\Core\Activity\Blocklist;
use Porro\Core\Activity\Cidr;
use Porro\Core\Activity\ClientIp;
use Porro\Core\Activity\CloudflareError;
use Porro\Core\Activity\Countries;
use Porro\Core\Activity\Entry;
use Porro\Core\Activity\FlaggedIp;
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
 * scheduled sync and the control panel, never by a page view.
 *
 * A local or staging copy (anything but production) enforces blocks but
 * doesn't record activity unless asked to, and never writes the nginx
 * file: it often has the live database, and its own traffic is noise.
 */
class Activity
{
    public const COMPILED = 'porro:blocklist:compiled';

    public const COMPILED_TTL = 300;

    /** Accounts above this are too many to match hashes against. */
    private const USERS_LIMIT = 5000;

    private ?ProtectedRanges $ranges = null;

    private ?Recorder $recorder = null;

    private ?Probes $probes = null;

    private bool $syncQueued = false;

    public function __construct(private Application $app) {}

    public function isProduction(): bool
    {
        return $this->app->environment('production', 'prod', 'live');
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
    public function ranges(): ProtectedRanges
    {
        return $this->ranges ??= new ProtectedRanges($this->cachedSources(), $this->strings('porro.blocklist.protected'));
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
            fn (string $key) => Cache::get($key),
            fn (string $key, mixed $value, int $ttl) => Cache::put($key, $value, $ttl),
            function (string $key, int $ttl) {
                Cache::add($key, 0, $ttl);
                Cache::increment($key);
            },
            $this->ranges(),
            (string) config('app.key'),
            fn (string $ip) => $this->compiled()['safe']->has($ip),
            (array) config('porro.activity.thresholds', []),
        );
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
            if (! Schema::hasTable('porro_blocklist')) {
                return [];
            }

            $this->freshRanges()->ranges();
            $removed = $this->blocklist()->prune();

            foreach ($removed as $entry) {
                Log::info("Porro: removed {$entry['cidr']} from the {$entry['list']}: {$entry['why']}");
            }

            Cache::forget(self::COMPILED);
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
     * Call after any change to either list.
     */
    public function changed(): void
    {
        Cache::forget(self::COMPILED);

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
        return $this->recorder()->summary($hours);
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
     * @return array{blocks: IpSet, safe: IpSet}
     */
    private function compiled(): array
    {
        try {
            $data = Cache::remember(self::COMPILED, self::COMPILED_TTL, function () {
                $list = $this->blocklist();

                return [
                    'blocks' => array_map(fn (Entry $e) => [$e->cidr, $e->note], $list->blocks()),
                    'safe' => array_map(fn (Entry $e) => $e->cidr, $list->safe()),
                ];
            });
        } catch (Throwable) {
            // No table yet, or no database: nothing is blocked.
            $data = ['blocks' => [], 'safe' => []];
        }

        $blocks = new IpSet;

        foreach ($data['blocks'] ?? [] as [$cidr, $note]) {
            $blocks->add(Cidr::tryParse((string) $cidr), (string) $note);
        }

        return ['blocks' => $blocks, 'safe' => IpSet::of($data['safe'] ?? [])];
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
