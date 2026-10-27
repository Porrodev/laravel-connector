<?php

namespace Porro\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Porro\Core\CrontabIo;
use Porro\Laravel\Collectors\BackupCollector;
use Porro\Laravel\Collectors\HealthCollector;
use Porro\Laravel\Collectors\LaravelCollector;
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
        $this->app->bind(CrontabIo::class);
        $this->app->tag([LaravelCollector::class, BackupCollector::class, HealthCollector::class], 'porro.collectors');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([__DIR__.'/../config/porro.php' => config_path('porro.php')], 'porro-config');
        $this->commands([ConnectCommand::class, CronCommand::class, ReportCommand::class, StatusCommand::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (! Reporting::enabled()) {
                return;
            }

            $interval = max(1, min(60, (int) config('porro.interval')));

            // Not connected: still note that the scheduler runs, so the
            // Site health panel can tell when cron is missing.
            if (! config('porro.key')) {
                $schedule->call(fn () => Heartbeat::beat())->name('porro:heartbeat')->cron("*/{$interval} * * * *");

                return;
            }

            // The scheduled report is the heartbeat: one task every few
            // minutes, so environments that scale to zero can still sleep.
            $schedule->command('porro:report', ['--scheduled'])->cron("*/{$interval} * * * *")->withoutOverlapping()->runInBackground();
        });
    }
}
