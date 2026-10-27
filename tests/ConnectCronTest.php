<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Porro\Core\CrontabIo;
use Porro\Laravel\Companions;
use Porro\Laravel\Heartbeat;
use Porro\Laravel\Tests\FakeCrontab;

beforeEach(function () {
    $this->app->setBasePath(__DIR__.'/fixtures/app');
    $this->env = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($this->env, "APP_NAME=Test\n");
    $this->app->loadEnvironmentFrom(basename($this->env));
    $this->app->useEnvironmentPath(dirname($this->env));
    $this->crontab = new FakeCrontab("0 3 * * * /usr/bin/backup\n");
    $this->app->instance(CrontabIo::class, $this->crontab);
    Http::fake(['porro.test/*' => Http::response(['ok' => true], 202)]);
});

it('offers to add the scheduler to the crontab and adds it', function () {
    $this->artisan('porro:connect', ['key' => siteKey()['token']])
        ->expectsConfirmation('Reports run on the scheduler, which needs one cron line. Add it to this server’s crontab now?', 'yes')
        ->expectsOutputToContain('Added the scheduler to this server’s crontab')
        ->assertSuccessful();

    expect($this->crontab->contents)->toStartWith("0 3 * * * /usr/bin/backup\n")
        ->toContain("* * * * * cd '".base_path()."' && ")
        ->toContain("artisan schedule:run > /dev/null 2>&1 # porro\n");
});

it('prints the line instead when the agency says no', function () {
    $this->artisan('porro:connect', ['key' => siteKey()['token']])
        ->expectsConfirmation('Reports run on the scheduler, which needs one cron line. Add it to this server’s crontab now?', 'no')
        ->expectsOutputToContain('One step left: add this cron line')
        ->assertSuccessful();

    expect($this->crontab->contents)->toBe("0 3 * * * /usr/bin/backup\n");
});

it('adds it without asking with --cron, for deploy scripts', function () {
    $this->artisan('porro:connect', ['key' => siteKey()['token'], '--cron' => true, '--no-interaction' => true])->assertSuccessful();

    expect($this->crontab->contents)->toContain('artisan schedule:run');
});

it('leaves the crontab alone with --no-cron or when not interactive', function (array $options) {
    $this->artisan('porro:connect', ['key' => siteKey()['token'], ...$options])
        ->expectsOutputToContain('One step left')
        ->assertSuccessful();

    expect($this->crontab->contents)->toBe("0 3 * * * /usr/bin/backup\n");
})->with([[['--no-cron' => true]], [['--no-interaction' => true]]]);

it('does not ask when the scheduler is already in the crontab', function () {
    $this->crontab->contents = '* * * * * cd '.base_path()." && php artisan schedule:run >> /dev/null 2>&1\n";

    $this->artisan('porro:connect', ['key' => siteKey()['token']])
        ->expectsOutputToContain('Found the scheduler in this server’s cron')
        ->assertSuccessful();
});

it('does not ask when the scheduler is already running', function () {
    Heartbeat::beat();

    $this->artisan('porro:connect', ['key' => siteKey()['token']])
        ->expectsOutputToContain('The scheduler is running')
        ->assertSuccessful();

    expect($this->crontab->contents)->toBe("0 3 * * * /usr/bin/backup\n");
});

it('prints the line when the server has no crontab access', function () {
    $this->crontab->works = false;

    $this->artisan('porro:connect', ['key' => siteKey()['token']])
        ->expectsOutputToContain('One step left')
        ->assertSuccessful();
});

it('tells Porro what sent each report', function () {
    $this->artisan('porro:connect', ['key' => siteKey()['token'], '--no-cron' => true])->assertSuccessful();
    config(['porro.key' => siteKey()['token']]);
    $this->artisan('porro:report')->assertSuccessful();
    $this->artisan('porro:report', ['--scheduled' => true])->assertSuccessful();

    expect(collect(Http::recorded())->map(fn ($pair) => json_decode($pair[0]->body(), true)['trigger'])->all())
        ->toBe(['connect', 'manual', 'schedule']);
});

it('adds the scheduler on its own with porro:cron, without the site key', function () {
    $this->artisan('porro:cron')
        ->expectsOutputToContain('Added the scheduler to this server’s crontab')
        ->assertSuccessful();

    expect($this->crontab->contents)->toContain('artisan schedule:run');
    Http::assertNothingSent();
});

it('prints the line from porro:cron when the server has no crontab access', function () {
    $this->crontab->works = false;

    $this->artisan('porro:cron')
        ->expectsOutputToContain('One step left')
        ->assertSuccessful();
});

it('tells Porro whether cron already runs the scheduler, and how often it reports', function () {
    $this->crontab->contents = '* * * * * php8.3 '.base_path()."/artisan schedule:run >> /dev/null 2>&1\n";

    $this->artisan('porro:connect', ['key' => siteKey()['token']])->assertSuccessful();

    $health = json_decode(Http::recorded()[0][0]->body(), true)['health'];

    expect($health['scheduler_in_crontab'])->toBeTrue()
        ->and($health['report_interval'])->toBe(15);
});

it('says when the scheduler is not in cron', function () {
    $this->artisan('porro:connect', ['key' => siteKey()['token'], '--no-cron' => true])->assertSuccessful();

    expect(json_decode(Http::recorded()[0][0]->body(), true)['health']['scheduler_in_crontab'])->toBeFalse();
});

it('refreshes a cached config so the scheduler sees the new key', function () {
    $cached = $this->app->getCachedConfigPath();
    @mkdir(dirname($cached), 0777, true);
    file_put_contents($cached, '<?php return [];');

    // The real command boots a fresh app from bootstrap/app.php.
    $recached = false;
    Artisan::command('config:cache', function () use (&$recached) {
        $recached = true;
    });

    try {
        $this->artisan('porro:connect', ['key' => siteKey()['token'], '--no-cron' => true])
            ->expectsOutputToContain('Refreshed the cached config')
            ->assertSuccessful();

        expect($recached)->toBeTrue();
    } finally {
        @unlink($cached);
    }
});

it('suggests the Statamic connector after connecting a Statamic site', function () {
    $this->app->instance(Companions::class, new Companions(fn ($package) => $package === 'statamic/cms'));

    $this->artisan('porro:connect', ['key' => siteKey()['token'], '--no-cron' => true])
        ->expectsOutputToContain('composer require porro/statamic-connector')
        ->expectsOutputToContain('php please porro:connect')
        ->assertSuccessful();
});
