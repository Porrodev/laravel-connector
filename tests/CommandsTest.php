<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Porro\Laravel\Reporter;

beforeEach(function () {
    $this->app->setBasePath(__DIR__.'/fixtures/app');
});

it('prints the exact report it would send, without sending it', function () {
    Http::fake();

    Artisan::call('porro:status', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($report)->toHaveKeys(['schema', 'connector', 'php', 'platform', 'health', 'lock'])
        ->and($report['connector']['name'])->toBe('porro/laravel-connector');
    Http::assertNothingSent();
});

it('sends nothing when reporting is turned off', function () {
    config(['porro.key' => siteKey()['token'], 'porro.enabled' => false]);
    Http::fake();

    $this->artisan('porro:report')->assertSuccessful();

    Http::assertNothingSent();
});

it('asks for a key before reporting', function () {
    config(['porro.key' => null]);
    Http::fake();

    $this->artisan('porro:report')->expectsOutputToContain('No PORRO_KEY set');

    Http::assertNothingSent();
});

it('leaves out backup and health results when spatie packages are not installed', function () {
    $report = app(Reporter::class)->build()->toArray();

    expect($report['health'])->not->toHaveKey('backup_configured')
        ->and($report['health'])->not->toHaveKey('last_backup_at')
        ->and($report['checks'] ?? [])->toBe([]);
});
