<?php

namespace Porro\Laravel\Activity;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Porro\Core\Activity\Cloudflare;
use Porro\Core\Activity\CloudflareError;
use Porro\Laravel\HttpSources;
use Throwable;

/**
 * Cloudflare WAF mode for this site: the token comes from the environment
 * (PORRO_CLOUDFLARE_TOKEN), never the database. The ids Cloudflare hands
 * back aren't secrets and can always be found again, so they live in the
 * cache.
 *
 * Only production syncs, and only to the zone it connected to: a local
 * copy with the live .env and a database dump must never push its stale
 * list to the account every site shares.
 */
class CloudflareMode
{
    public const STATE = 'porro:cloudflare';

    public function __construct(private Activity $activity) {}

    public function configured(): bool
    {
        return $this->token() !== '';
    }

    /**
     * This site's tag on the shared list's items. Derived from the app
     * key, so it's stable without storing anything, and differs between
     * sites (and between a site's environments).
     */
    public function siteId(): string
    {
        return substr(hash_hmac('sha256', 'porro-cloudflare', (string) config('app.key')), 0, 12);
    }

    public function host(): string
    {
        $host = config('porro.blocklist.cloudflare_host') ?: parse_url((string) config('app.url'), PHP_URL_HOST);

        return strtolower((string) $host);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function state(): ?array
    {
        $state = Cache::get(self::STATE);

        return is_array($state) ? $state : null;
    }

    public function client(): Cloudflare
    {
        return app()->bound(Cloudflare::class)
            ? app(Cloudflare::class)
            : new Cloudflare($this->token(), HttpSources::transport());
    }

    /**
     * Checks the token, then finds or creates the list and the rule.
     *
     * @return array<string, mixed> the connection
     *
     * @throws CloudflareError
     */
    public function connect(): array
    {
        $client = $this->client();
        $client->verify();
        $connection = $client->connect($this->host());

        $this->remember(['connection' => $connection, 'error' => null]);

        return $connection;
    }

    /**
     * Makes Cloudflare's list match the blocklist. Never throws: problems
     * are kept for the Blocking page and logged.
     *
     * @return array{added: int, removed: int, shared: int}|null null when it didn't run
     */
    public function sync(): ?array
    {
        if (! $this->configured() || ! $this->activity->isProduction()) {
            return null;
        }

        try {
            $connection = $this->state()['connection'] ?? $this->connect();

            // The saved zone must still serve this site. A copy of the site on
            // another host (staging on a different domain) stops here.
            if (! $this->serves((string) $connection['zone_name'])) {
                throw new CloudflareError("This site is {$this->host()}, but Cloudflare is connected to {$connection['zone_name']}. Run porro:cloudflare connect again.");
            }

            $result = $this->client()->sync($connection, $this->siteId(), $this->activity->blocklist()->blocks());
            $this->remember(['error' => null, 'synced_at' => time(), 'result' => $result]);

            return $result;
        } catch (Throwable $e) {
            $this->remember(['error' => $e->getMessage(), 'failed_at' => time()]);
            Log::warning('Porro: Cloudflare sync failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * @throws CloudflareError
     */
    public function disconnect(): void
    {
        if ($connection = $this->state()['connection'] ?? null) {
            $this->client()->disconnect($connection, $this->siteId());
        }

        Cache::forget(self::STATE);
    }

    /**
     * What the Blocking page shows.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $state = $this->state() ?? [];

        return [
            'configured' => $this->configured(),
            'connected' => isset($state['connection']),
            'zone' => $state['connection']['zone_name'] ?? null,
            'production' => $this->activity->isProduction(),
            'synced_at' => $state['synced_at'] ?? null,
            'result' => $state['result'] ?? null,
            'error' => $state['error'] ?? null,
        ];
    }

    private function serves(string $zone): bool
    {
        $host = $this->host();

        return $host === $zone || str_ends_with($host, '.'.$zone);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function remember(array $changes): void
    {
        Cache::forever(self::STATE, array_merge($this->state() ?? [], $changes));
    }

    private function token(): string
    {
        return trim((string) config('porro.blocklist.cloudflare_token'));
    }
}
