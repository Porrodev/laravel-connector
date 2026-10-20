<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Porro\Laravel\Heartbeat;
use Porro\Laravel\Reporter;

beforeEach(function () {
    $this->app->setBasePath(__DIR__.'/fixtures/app');
});

it('reports Laravel facts without any values or secrets', function () {
    config(['app.debug' => true, 'app.key' => 'base64:SECRET', 'database.connections.testing.password' => 'hunter2']);
    Heartbeat::beat();

    $report = app(Reporter::class)->build()->toArray();

    expect($report['platform'])->toMatchArray(['name' => 'laravel', 'version' => app()->version()])
        ->and($report['health']['debug'])->toBeTrue()
        ->and($report['health']['scheduler_last_run'])->not->toBeNull()
        ->and($report['lock']['packages'])->toHaveCount(4)
        ->and(json_encode($report))->not->toContain('SECRET')->not->toContain('hunter2');
});

it('counts failed jobs from the last 24 hours', function () {
    config(['queue.failed.driver' => 'database-uuids', 'queue.failed.table' => 'failed_jobs', 'queue.failed.database' => 'testing']);
    Schema::create('failed_jobs', function ($table) {
        $table->id();
        $table->string('uuid');
        $table->timestamp('failed_at');
    });
    DB::table('failed_jobs')->insert([['uuid' => 'a', 'failed_at' => now()->subHour()], ['uuid' => 'b', 'failed_at' => now()->subDays(3)]]);

    expect(app(Reporter::class)->build()->toArray()['health']['failed_jobs_24h'])->toBe(1);
});

it('sends packages only when the lock file changed or Porro asks', function () {
    $key = siteKey()['token'];
    config(['porro.key' => $key]);
    Http::fake(['porro.test/*' => Http::sequence()->push(['ok' => true], 202)->push(['ok' => true], 202)->push(['ok' => true, 'need_lock' => true], 202)->push(['ok' => true], 202)]);

    $reporter = app(Reporter::class);
    $reporter->send();
    $reporter->send();
    Cache::forget('porro:lock-hash'); // as if Porro lost it
    $reporter->send(withPackages: false);

    $bodies = collect(Http::recorded())->map(fn ($pair) => json_decode($pair[0]->body(), true));

    expect($bodies[0]['lock']['packages'])->toBeArray()
        ->and($bodies[1]['lock']['packages'])->toBeNull();
});

it('connects, writes the key to .env and sends the first report', function () {
    $env = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($env, "APP_NAME=Test\n");
    $this->app->loadEnvironmentFrom(basename($env));
    $this->app->useEnvironmentPath(dirname($env));
    $key = siteKey()['token'];
    Http::fake(['porro.test/*' => Http::response(['ok' => true], 202)]);

    $this->artisan('porro:connect', ['key' => $key])->assertSuccessful();

    expect(file_get_contents($env))->toContain("PORRO_KEY={$key}");
    Http::assertSent(fn (Request $request) => $request->url() === 'https://porro.test/api/v1/reports' && $request->hasHeader('X-Porro-Signature'));
});

it('explains a bad key instead of sending', function () {
    Http::fake();

    $this->artisan('porro:connect', ['key' => 'lk_site_nope'])->assertFailed();

    Http::assertNothingSent();
});

it('reports on the schedule once a key is set', function () {
    config(['porro.key' => siteKey()['token'], 'porro.interval' => 15]);

    $events = collect(app(Schedule::class)->events());

    expect($events->first(fn ($e) => str_contains((string) $e->command, 'porro:report'))?->expression)->toBe('*/15 * * * *')
        ->and($events->contains(fn ($e) => $e->description === 'porro:heartbeat'))->toBeTrue();
});
