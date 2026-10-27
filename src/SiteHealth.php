<?php

namespace Porro\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Porro\Core\Activity\Countries;
use Porro\Core\Activity\FlaggedIp;
use Porro\Core\Cron;
use Porro\Core\CrontabIo;
use Porro\Core\Health\Issue;
use Porro\Core\Health\PorroFindings;
use Porro\Core\Health\ReportChecks;
use Porro\Core\Health\SiteHealth as Checks;
use Porro\Core\Health\Sources;
use Porro\Core\LockFile;
use Porro\Core\Report;
use Porro\Core\SiteKey;
use Porro\Laravel\Activity\Activity;
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

            if ($key = $this->key()) {
                (new PorroFindings($this->sources(), $key, (string) config('porro.url')))->forget();
            }
        }

        return Cache::remember(self::CACHE_KEY, self::TTL, fn () => $this->run());
    }

    /**
     * Drops the cached result, so the next view shows a change straight
     * away (e.g. after blocking an IP) without fetching anything again.
     */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The last result, without running any checks: for places that must
     * never wait on the network, like a dashboard widget.
     *
     * @return array<string, mixed>|null
     */
    public function cached(): ?array
    {
        $health = Cache::get(self::CACHE_KEY);

        return is_array($health) ? $health : null;
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

        $activity = $this->activity($health);

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
            'enabled' => Reporting::enabled(),
            'activity' => $activity,
        ];
    }

    /**
     * Adds suspicious activity to the issues, and returns what the
     * Activity section shows: totals, flagged IPs with a rough country,
     * and how many entries each list has.
     *
     * @return array<string, mixed>|null
     */
    private function activity(Checks $health): ?array
    {
        try {
            $activity = $this->app->make(Activity::class);
            $summary = $activity->summary();

            foreach ($activity->issues($summary) as $issue) {
                $health->add($issue);
            }

            $health->add(null, 'suspicious activity');
            $list = $activity->blocklist();
            $countries = $activity->countries();

            return [
                'recording' => $activity->recording(),
                'hours' => $summary->hours,
                'totals' => $summary->totals,
                'flagged' => array_map(fn (FlaggedIp $ip) => $ip->toArray(), $activity->flagged($summary)),
                'blocked' => count($list->blocks()),
                'safelisted' => count($list->safe()),
                'countries' => $countries->available() ? ['attribution' => Countries::ATTRIBUTION, 'url' => Countries::ATTRIBUTION_URL] : null,
            ];
        } catch (Throwable) {
            return null;
        }
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

        if (! is_string($key) || $key === '' || ! Reporting::enabled()) {
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
        return HttpSources::make();
    }
}
