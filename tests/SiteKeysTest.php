<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Porro\Core\SiteKey;
use Porro\Laravel\Reporter;
use Porro\Laravel\SiteHealth;
use Porro\Laravel\SiteKeys;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->setBasePath(__DIR__.'/fixtures/app');
    config(['porro.key' => null]);
});

function storedKey(): SiteKey
{
    return SiteKey::generate()->withSite('01jabcdefghjkmnpqrstvwxyz0');
}

it('has no key until the site is connected', function () {
    $keys = app(SiteKeys::class);

    expect($keys->token())->toBeNull()
        ->and($keys->connected())->toBeFalse()
        ->and($keys->source())->toBeNull()
        ->and($keys->ready())->toBeTrue();
});

it('keeps a key made in the control panel encrypted in the site’s database', function () {
    $key = storedKey();
    app(SiteKeys::class)->store($key, 'Marlowe Studio', 'harbour-arts.org');

    $row = DB::table('porro_settings')->where('key', 'site_key')->value('value');

    // A fresh instance, as the next request would have.
    $keys = new SiteKeys;

    expect($row)->not->toContain($key->seed())
        ->and($row)->not->toContain('porro_')
        ->and($keys->token())->toBe($key->token())
        ->and($keys->source())->toBe(SiteKeys::STORED)
        ->and($keys->stored())->toMatchArray(['workspace' => 'Marlowe Studio', 'host' => 'harbour-arts.org'])
        ->and($keys->stored()['connected_at'])->not->toBeNull();
});

it('lets PORRO_KEY in the environment win over the stored key', function () {
    $env = siteKey()['token'];
    app(SiteKeys::class)->store(storedKey());
    config(['porro.key' => $env]);

    $keys = new SiteKeys;

    expect($keys->token())->toBe($env)
        ->and($keys->source())->toBe(SiteKeys::ENV)
        // Still there for when the environment's key is taken away.
        ->and($keys->stored())->not->toBeNull();
});

it('treats a key it can’t read as no key', function () {
    app(SiteKeys::class)->store(storedKey());

    // The app key changed since it was stored.
    config(['app.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstances();

    expect((new SiteKeys)->token())->toBeNull();

    DB::table('porro_settings')->update(['value' => 'not encrypted at all']);

    expect((new SiteKeys)->token())->toBeNull();
});

it('has no key, and says it can’t keep one, before the migration has run', function () {
    Schema::drop('porro_settings');

    $keys = new SiteKeys;

    expect($keys->token())->toBeNull()
        ->and($keys->ready())->toBeFalse()
        ->and(fn () => $keys->store(storedKey()))->toThrow(QueryException::class);
});

it('forgets the stored key', function () {
    $keys = app(SiteKeys::class);
    $keys->store(storedKey());
    $keys->forget();

    expect((new SiteKeys)->token())->toBeNull()
        ->and(DB::table('porro_settings')->count())->toBe(0);
});

it('sends reports signed with the stored key', function () {
    $key = storedKey();
    app(SiteKeys::class)->store($key);
    Http::fake(['porro.test/*' => Http::response(['ok' => true], 202)]);

    expect(app(Reporter::class)->send()->ok)->toBeTrue();

    $sent = Http::recorded()[0][0];

    expect($sent->header('X-Porro-Site')[0])->toBe('01JABCDEFGHJKMNPQRSTVWXYZ0')
        ->and(signatureIsValid(array_map(fn ($v) => $v[0], $sent->headers()), $sent->body(), base64_decode($key->publicKey())))->toBeTrue();
});

it('runs porro:report and the Site health panel from the stored key', function () {
    app(SiteKeys::class)->store(storedKey());
    Http::fake(['*' => Http::response(['ok' => true, 'findings' => []], 202)]);

    $this->artisan('porro:report')->expectsOutputToContain('Report sent to Porro.')->assertSuccessful();

    expect(app(SiteHealth::class)->key())->not->toBeNull();

    // Reporting off: connected or not, the panel never calls Porro.
    config(['porro.enabled' => false]);

    expect(app(SiteHealth::class)->key())->toBeNull();
});

it('says where the key comes from in porro:status', function () {
    $this->artisan('porro:status')->expectsOutputToContain('no');

    app(SiteKeys::class)->store(storedKey());
    app()->forgetScopedInstances();

    $this->artisan('porro:status')->expectsOutputToContain('yes (connected from the control panel)');

    config(['porro.key' => siteKey()['token']]);

    $this->artisan('porro:status')->expectsOutputToContain('yes (PORRO_KEY)');
});

it('schedules reports once the site is connected from the control panel', function () {
    app(SiteKeys::class)->store(storedKey());

    $porro = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command.$e->description, 'porro'));

    expect($porro)->toHaveCount(1)
        ->and((string) $porro->first()->command)->toContain('porro:report')->toContain('--scheduled');
});

it('never has the key in what it says about the connection, or in its own state', function () {
    $key = storedKey();
    $keys = app(SiteKeys::class);
    $keys->store($key, 'Marlowe Studio', 'harbour-arts.org');

    // Read both ways first, so anything it was going to keep, it has.
    $token = $keys->token();
    $about = $keys->stored();
    $dumped = json_encode($about).print_r($about, true).var_export($about, true)
        .print_r($keys, true).var_export($keys, true).json_encode((array) $keys);

    expect($token)->toBe($key->token())
        ->and(array_keys($about))->toBe(['workspace', 'host', 'connected_at'])
        ->and($dumped)->not->toContain($key->seed())
        ->and($dumped)->not->toContain('porro_01jabc')
        ->and($dumped)->toContain('Marlowe Studio');
});

it('checks it can keep a key with a real write, and leaves nothing behind', function () {
    expect(app(SiteKeys::class)->ready())->toBeTrue()
        ->and(DB::table('porro_settings')->count())->toBe(0);
});
