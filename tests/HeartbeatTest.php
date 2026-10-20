<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Porro\Laravel\Heartbeat;

it('keeps the heartbeat in a file under storage', function () {
    Carbon::setTestNow('2026-10-20 09:15:00');

    Heartbeat::beat();

    expect(storage_path('framework/porro-heartbeat'))->toBeFile()
        ->and(file_get_contents(storage_path('framework/porro-heartbeat')))->toBe(now()->toIso8601String())
        ->and(Heartbeat::lastRun())->toBe(now()->toIso8601String())
        ->and(glob(storage_path('framework/porro-heartbeat.*')))->toBe([]);
});

it('survives a deploy clearing the cache', function () {
    Heartbeat::beat();

    Cache::flush();
    $this->artisan('cache:clear')->assertSuccessful();

    expect(Heartbeat::lastRun())->not->toBeNull();
});

it('falls back to the cache key older connectors wrote', function () {
    Cache::forever('porro:scheduler:last-run', '2026-10-19T08:00:00+00:00');

    expect(Heartbeat::lastRun())->toBe('2026-10-19T08:00:00+00:00');
});

it('prefers the file over the old cache key', function () {
    Cache::forever('porro:scheduler:last-run', '2026-10-19T08:00:00+00:00');
    Carbon::setTestNow('2026-10-20 09:15:00');

    Heartbeat::beat();

    expect(Heartbeat::lastRun())->toBe(now()->toIso8601String());
});

it('says the scheduler never ran when there is no heartbeat', function () {
    expect(Heartbeat::lastRun())->toBeNull();
});

it('never throws when storage cannot be written', function () {
    $blocker = tempnam(sys_get_temp_dir(), 'porro');
    $this->app->useStoragePath($blocker);

    try {
        Heartbeat::beat();

        expect(Heartbeat::lastRun())->toBeNull();
    } finally {
        @unlink($blocker);
    }
});
