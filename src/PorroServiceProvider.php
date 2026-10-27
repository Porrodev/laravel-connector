<?php

namespace Porro\Laravel;

use Illuminate\Auth\Events\Failed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Porro\Core\CrontabIo;
use Porro\Laravel\Activity\Activity;
use Porro\Laravel\Activity\RecordFailedLogin;
use Porro\Laravel\Activity\WatchRequests;
use Porro\Laravel\Collectors\BackupCollector;
use Porro\Laravel\Collectors\HealthCollector;
use Porro\Laravel\Collectors\LaravelCollector;
use Porro\Laravel\Commands\BlockCommand;
use Porro\Laravel\Commands\BlocklistSyncCommand;
use Porro\Laravel\Commands\ConnectCommand;
use Porro\Laravel\Commands\CronCommand;
use Porro\Laravel\Commands\ReportCommand;
use Porro\Laravel\Commands\StatusCommand;

class PorroServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/porro.php', 'porro');

        $this->app->singleton(Reporter::class);
        $this->app->singleton(Activity::class);
        $this->app->bind(CrontabIo::class);
        $this->app->tag([LaravelCollector::class, BackupCollector::class, HealthCollector::class], 'porro.collectors');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->watchRequests();

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([__DIR__.'/../config/porro.php' => config_path('porro.php')], 'porro-config');
        $this->commands([BlockCommand::class, BlocklistSyncCommand::class, ConnectCommand::class, CronCommand::class, ReportCommand::class, StatusCommand::class]);

        // nginx mode only: every server writes its own deny file, so this runs
        // every minute on each (never onOneServer). Otherwise the blocklist is
        // kept tidy by the one task below, so scale-to-zero hosts can sleep.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (config('porro.blocklist.nginx')) {
                $schedule->command('porro:blocklist-sync')->everyMinute()->withoutOverlapping()->runInBackground();
            }
        });

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (! config('porro.enabled')) {
                return;
            }

            $interval = max(1, min(60, (int) config('porro.interval')));

            // Not connected: still note that the scheduler runs, so the
            // Site health panel can tell when cron is missing.
            if (! config('porro.key')) {
                $schedule->call(function () {
                    Heartbeat::beat();
                    $this->app->make(Activity::class)->maintain();
                })->name('porro:heartbeat')->cron("*/{$interval} * * * *");

                return;
            }

            // The scheduled report is the heartbeat: one task every few
            // minutes, so environments that scale to zero can still sleep.
            $schedule->command('porro:report', ['--scheduled'])->cron("*/{$interval} * * * *")->withoutOverlapping()->runInBackground();
        });
    }

    /**
     * First in the global stack, so blocked IPs cost nothing and scanner
     * requests that match no route are still seen.
     */
    private function watchRequests(): void
    {
        $this->app->booted(function () {
            $kernel = $this->app->make(HttpKernel::class);

            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(WatchRequests::class);
            }
        });

        Event::listen(Failed::class, RecordFailedLogin::class);
    }
}
