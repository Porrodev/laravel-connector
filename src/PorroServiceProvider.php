<?php

namespace Porro\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Porro\Laravel\Collectors\BackupCollector;
use Porro\Laravel\Collectors\HealthCollector;
use Porro\Laravel\Collectors\LaravelCollector;
use Porro\Laravel\Commands\ConnectCommand;
use Porro\Laravel\Commands\ReportCommand;
use Porro\Laravel\Commands\StatusCommand;

class PorroServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/porro.php', 'porro');

        $this->app->singleton(Reporter::class);
        $this->app->tag([LaravelCollector::class, BackupCollector::class, HealthCollector::class], 'porro.collectors');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([__DIR__.'/../config/porro.php' => config_path('porro.php')], 'porro-config');
        $this->commands([ConnectCommand::class, ReportCommand::class, StatusCommand::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (! config('porro.enabled') || ! config('porro.key')) {
                return;
            }

            $interval = max(1, min(60, (int) config('porro.interval')));

            $schedule->call(fn () => Heartbeat::beat())->everyMinute()->name('porro:heartbeat');
            $schedule->command('porro:report')->cron("*/{$interval} * * * *")->withoutOverlapping()->runInBackground();
        });
    }
}
