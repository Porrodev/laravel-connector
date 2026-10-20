<?php

namespace Porro\Laravel;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Porro\Core\Client;
use Porro\Core\LockFile;
use Porro\Core\NpmLockFile;
use Porro\Core\Report;
use Porro\Core\Result;
use Porro\Core\SiteKey;

/**
 * Builds a report from every collector and sends it. Each package list
 * (composer.lock, and the npm lock file if there is one) is only included
 * when that file has changed since Porro last saw it.
 */
class Reporter
{
    private const SENT_LOCK_KEY = 'porro:lock-hash';

    private const SENT_NPM_LOCK_KEY = 'porro:npm-lock-hash';

    public function __construct(private Application $app) {}

    public function build(bool $withPackages = false): Report
    {
        $report = new Report($this->connectorName(), 'laravel');

        foreach ($this->app->tagged('porro.collectors') as $collector) {
            /** @var Collector $collector */
            $collector->collect($report);
        }

        $lock = LockFile::read($this->app->basePath());
        $report->lock($lock, $withPackages || ($lock && Cache::get(self::SENT_LOCK_KEY) !== $lock->hash));

        // The JavaScript the site builds with (Vite, Alpine…), same rules.
        $npm = NpmLockFile::read($this->app->basePath());
        $report->npmLock($npm, $withPackages || ($npm && Cache::get(self::SENT_NPM_LOCK_KEY) !== $npm->hash));

        return $report;
    }

    /**
     * @param  array<string, mixed>  $health  extra health facts for this report only
     */
    public function send(?string $key = null, ?string $url = null, bool $withPackages = false, string $trigger = 'manual', array $health = []): Result
    {
        $client = new Client(
            SiteKey::parse((string) ($key ?? config('porro.key'))),
            (string) ($url ?? config('porro.url')),
            $this->transport(),
        );

        $report = $this->withHealth($this->build($withPackages)->trigger($trigger), $health);
        $result = $client->send($report);

        if ($result->ok && (($result->needLock && ! $report->includesPackages()) || ($result->needNpmLock && ! $report->includesNpmPackages()))) {
            $report = $this->withHealth($this->build(true)->trigger($trigger), $health);
            $result = $client->send($report);
        }

        if ($result->ok && $report->includesPackages()) {
            Cache::forever(self::SENT_LOCK_KEY, $report->lockHash());
        }

        if ($result->ok && $report->includesNpmPackages()) {
            Cache::forever(self::SENT_NPM_LOCK_KEY, $report->npmLockHash());
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $health
     */
    private function withHealth(Report $report, array $health): Report
    {
        foreach ($health as $key => $value) {
            $report->health($key, $value);
        }

        return $report;
    }

    /**
     * The connector package installed on this site, e.g. porro/statamic-connector.
     */
    private function connectorName(): string
    {
        foreach (['porro/statamic-connector', 'porro/filament-connector'] as $package) {
            if (Report::installedVersion($package) !== null) {
                return $package;
            }
        }

        return 'porro/laravel-connector';
    }

    /**
     * @return Closure(string, array<string, string>, string): array{0: int, 1: string}
     */
    private function transport(): Closure
    {
        return function (string $url, array $headers, string $body): array {
            try {
                $response = Http::withHeaders($headers)->timeout(15)->withBody($body, 'application/json')->post($url);

                return [$response->status(), $response->body()];
            } catch (\Throwable $e) {
                return [0, $e->getMessage()];
            }
        };
    }
}
