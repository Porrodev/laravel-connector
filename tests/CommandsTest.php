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

it('reports a deploy, and never fails the deploy script', function () {
    config(['porro.key' => siteKey()['token']]);
    Http::fake(['porro.test/*' => Http::sequence()
        ->push(['ok' => true], 202)
        ->whenEmpty(Http::response(['ok' => false, 'error' => 'Invalid signature.'], 401))]);

    $this->artisan('porro:report', ['--deploy' => true])->assertSuccessful();
    expect(json_decode(Http::recorded()[0][0]->body(), true)['trigger'])->toBe('deploy');

    // Porro refusing it, or no key yet, still lets the deploy finish.
    $this->artisan('porro:report', ['--deploy' => true])->assertSuccessful();
    $this->artisan('porro:report')->assertFailed();

    config(['porro.key' => null]);
    $this->artisan('porro:report', ['--deploy' => true])->assertSuccessful();
});

it('leaves out backup and health results when spatie packages are not installed', function () {
    $report = app(Reporter::class)->build()->toArray();

    expect($report['health'])->not->toHaveKey('backup_configured')
        ->and($report['health'])->not->toHaveKey('last_backup_at')
        ->and($report['health'])->not->toHaveKey('checks');
});
