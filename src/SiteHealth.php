<?php

namespace Porro\Laravel;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Porro\Core\Cron;
use Porro\Core\CrontabIo;
use Porro\Core\Health\Issue;
use Porro\Core\Health\ReportChecks;
use Porro\Core\Health\SiteHealth as Checks;
use Porro\Core\Health\Sources;
use Porro\Core\LockFile;
use Porro\Core\Report;
use Porro\Core\SiteKey;
use Statamic\Statamic;
use Throwable;

/**
 * What the "Site health" panel shows in the Statamic and Filament control
 * panels. Works without a Porro account: PHP and Laravel support dates,
 * security advisories in composer.lock, Laravel updates, the scheduler,
 * debug mode, migrations and failed jobs. Once connected, the site's
 * open findings in Porro too.
 *
 * Statamic and Filament add their own checks with the "porro.health" tag:
 * classes with a check(Checks $health): void method.
 */
class SiteHealth
{
    private const CACHE_KEY = 'porro:health:panel';

    private const TTL = 300;

    public function __construct(private Application $app, private Reporter $reporter) {}

    /**
     * @param  bool  $fresh  check again now, rather than use the last five minutes' result
     * @return array<string, mixed>
     */
    public function get(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
            $this->sources()->forget('porro:'.($this->key()?->siteId ?? ''));
        }

        return Cache::remember(self::CACHE_KEY, self::TTL, fn () => $this->run());
    }

    /**
     * @return array<string, mixed>
     */
    private function run(): array
    {
        $health = new Checks($this->sources());

        $health->endOfLife(['php' => PHP_VERSION, 'laravel' => $this->app->version()]);

        try {
            $report = $this->reporter->build()->toArray();
            $health->report($report);
        } catch (Throwable) {
            $health->unavailable('Couldn’t read the site’s own health facts');
        }

        $health->advisories(LockFile::read($this->app->basePath()));
        $health->updates(['laravel/framework' => 'Laravel'], ['laravel/framework' => Report::installedVersion('laravel/framework')]);
        $health->add($this->scheduler(), 'the scheduler');

        foreach ($this->app->tagged('porro.health') as $check) {
            try {
                $check->check($health);
            } catch (Throwable) {
                // One broken check never takes the panel down.
            }
        }

        return $health->toArray($this->key(), (string) config('porro.url')) + [
            'commands' => [
                'connect' => $this->cli().' porro:connect <site key>',
                'cron' => $this->cli().' porro:cron',
            ],
            'enabled' => (bool) config('porro.enabled'),
        ];
    }

    private function scheduler(): ?Issue
    {
        $directory = $this->app->basePath();

        try {
            $inCrontab = Cron::isScheduled($directory, 'artisan schedule:run', $this->app->make(CrontabIo::class));
        } catch (Throwable) {
            $inCrontab = null;
        }

        // The heartbeat is written every few minutes, connected or not.
        $stale = max(1, min(60, (int) config('porro.interval'))) * 2 + 5;

        return (new ReportChecks)->scheduler(Heartbeat::lastRun(), $inCrontab, 'Scheduler', $this->cli().' porro:cron', $stale);
    }

    /**
     * The site key, when the site is connected and the key is valid.
     */
    public function key(): ?SiteKey
    {
        $key = config('porro.key');

        if (! is_string($key) || $key === '' || ! config('porro.enabled')) {
            return null;
        }

        try {
            return SiteKey::parse($key);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * "php please" on Statamic sites, "php artisan" elsewhere.
     */
    private function cli(): string
    {
        return class_exists(Statamic::class) ? 'php please' : 'php artisan';
    }

    private function sources(): Sources
    {
        return new Sources(
            fn (string $key) => Cache::get($key),
            fn (string $key, mixed $value, int $ttl) => Cache::put($key, $value, $ttl),
            $this->transport(),
        );
    }

    /**
     * Laravel's HTTP client, so Http::fake() works in tests.
     *
     * @return Closure(string, string, array<string, string>, string|null, int): array{0: int, 1: string}
     */
    private function transport(): Closure
    {
        return function (string $method, string $url, array $headers, ?string $body, int $timeout): array {
            try {
                $request = Http::withHeaders($headers)->timeout($timeout);

                if ($body !== null) {
                    $request = $request->withBody($body, $headers['Content-Type'] ?? 'application/json');
                }

                $response = $request->send($method, $url);

                return [$response->status(), $response->body()];
            } catch (Throwable $e) {
                return [0, $e->getMessage()];
            }
        };
    }
}
