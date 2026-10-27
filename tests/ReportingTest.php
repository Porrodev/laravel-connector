<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Porro\Laravel\Reporting;

beforeEach(function () {
    $this->app->setBasePath(__DIR__.'/fixtures/app');
});

it('stays quiet outside production when PORRO_ENABLED is not set', function (string $env) {
    $this->app['env'] = $env;
    config(['porro.key' => siteKey()['token'], 'porro.enabled' => null]);
    Http::fake();

    $this->artisan('porro:report')
        ->expectsOutputToContain("not production (APP_ENV={$env})")
        ->assertSuccessful();

    expect(Reporting::enabled())->toBeFalse();
    Http::assertNothingSent();
})->with(['local', 'staging']);

it('reports from production when PORRO_ENABLED is not set', function () {
    $this->app['env'] = 'production';
    config(['porro.enabled' => null]);

    expect(Reporting::enabled())->toBeTrue()
        ->and(Reporting::offReason())->toBeNull();
});

it('lets an explicit PORRO_ENABLED win over the environment', function () {
    $this->app['env'] = 'local';
    config(['porro.enabled' => 'true']);
    expect(Reporting::enabled())->toBeTrue();

    $this->app['env'] = 'production';
    config(['porro.enabled' => false]);
    expect(Reporting::offReason())->toBe('PORRO_ENABLED=false');
});

it('says why reporting is off in porro:status', function () {
    $this->app['env'] = 'local';
    config(['porro.enabled' => null]);
    Http::fake();

    Artisan::call('porro:status');

    expect(Artisan::output())->toContain('off: not production (APP_ENV=local)');
});
