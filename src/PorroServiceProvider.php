<?php

namespace Porro\Laravel;

use Filament\FilamentServiceProvider;
use Illuminate\Auth\Events\Failed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Porro\Core\CrontabIo;
use Porro\Laravel\Activity\Activity;
use Porro\Laravel\Activity\CloudflareMode;
use Porro\Laravel\Activity\RecordFailedLogin;
use Porro\Laravel\Activity\WatchRequests;
use Porro\Laravel\Collectors\BackupCollector;
use Porro\Laravel\Collectors\HealthCollector;
use Porro\Laravel\Collectors\LaravelCollector;
use Porro\Laravel\Commands\BlockCommand;
use Porro\Laravel\Commands\BlocklistSyncCommand;
use Porro\Laravel\Commands\CloudflareCommand;
use Porro\Laravel\Commands\ConnectCommand;
use Porro\Laravel\Commands\CronCommand;
use Porro\Laravel\Commands\ReportCommand;
use Porro\Laravel\Commands\StatusCommand;
use Porro\Laravel\Http\Authorize;
use Porro\Laravel\Http\PageController;
use Statamic\Statamic;

class PorroServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/porro.php', 'porro');

        // Porro's own store, in its own table, so cache:clear on deploy
        // leaves activity alone. An app can define cache.stores.porro itself.
        if (! config('cache.stores.'.Activity::STORE)) {
            config(['cache.stores.'.Activity::STORE => [
                'driver' => 'database',
                'connection' => null,
                'table' => Activity::TABLE,
                'lock_connection' => null,
                'lock_table' => Activity::TABLE.'_locks',
            ]]);
        }

        $this->app->singleton(Reporter::class);
        $this->app->singleton(Activity::class);
        $this->app->singleton(CloudflareMode::class);
        $this->app->singleton(Activity\NginxServers::class);
        $this->app->bind(CrontabIo::class);
        $this->app->tag([LaravelCollector::class, BackupCollector::class, HealthCollector::class], 'porro.collectors');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'porro-laravel');
        $this->watchRequests();
        $this->page();

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([__DIR__.'/../config/porro.php' => config_path('porro.php')], 'porro-config');
        $this->commands([BlockCommand::class, BlocklistSyncCommand::class, CloudflareCommand::class, ConnectCommand::class, CronCommand::class, ReportCommand::class, StatusCommand::class]);

        // nginx mode only: every server writes its own deny file, so this runs
        // every minute on each (never onOneServer). Otherwise the blocklist is
        // kept tidy by the one task below, so scale-to-zero hosts can sleep.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (config('porro.blocklist.nginx')) {
                $schedule->command('porro:blocklist-sync')->everyMinute()->withoutOverlapping()->runInBackground();
            }
        });

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (! Reporting::enabled()) {
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

    public static function pageEnabled(): bool
    {
        $enabled = config('porro.page.enabled');

        return $enabled === null
            ? ! class_exists(Statamic::class) && ! class_exists(FilamentServiceProvider::class)
            : filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The /porro page. Skipped when Statamic or Filament shows the same
     * things in its own control panel, unless PORRO_PAGE says otherwise.
     */
    private function page(): void
    {
        if (! self::pageEnabled() || $this->app->routesAreCached()) {
            return;
        }

        Route::middleware([...(array) config('porro.page.middleware', ['web']), Authorize::class])
            ->prefix(trim((string) config('porro.page.path', 'porro'), '/'))
            ->name('porro.')
            ->group(function () {
                Route::get('/', [PageController::class, 'health'])->name('health');
                Route::get('activity', [PageController::class, 'activity'])->name('activity');
                Route::get('activity/{ip}', [PageController::class, 'ip'])->name('ip')->where('ip', '[0-9A-Fa-f:.]+');
                Route::post('activity/{ip}/reputation', [PageController::class, 'reputation'])->name('reputation')->where('ip', '[0-9A-Fa-f:.]+');
                Route::get('blocking', [PageController::class, 'blocking'])->name('blocking');
                Route::get('blocking/nginx', [PageController::class, 'nginx'])->name('nginx');
                Route::get('blocking/cloudflare', [PageController::class, 'cloudflare'])->name('cloudflare');
                Route::post('blocking/cloudflare/connect', [PageController::class, 'cloudflareConnect'])->name('cloudflare.connect');
                Route::get('blocking/{list}', [PageController::class, 'list'])->name('list')->whereIn('list', ['blocklist', 'safelist']);
                Route::post('refresh', [PageController::class, 'refresh'])->name('refresh');
                Route::post('blocking/block', [PageController::class, 'block'])->name('block');
                Route::post('blocking/unblock', [PageController::class, 'unblock'])->name('unblock');
                Route::post('blocking/safelist', [PageController::class, 'safelist'])->name('safelist');
                Route::post('blocking/unsafelist', [PageController::class, 'unsafelist'])->name('unsafelist');
            });
    }
}
