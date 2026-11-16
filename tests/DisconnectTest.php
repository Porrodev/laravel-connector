<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as Sent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Porro\Core\SiteKey;
use Porro\Laravel\Connect\Disconnect;
use Porro\Laravel\Reporter;
use Porro\Laravel\SiteKeys;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->setBasePath(__DIR__.'/fixtures/app');
    $this->app['env'] = 'production';
    config(['porro.key' => null, 'porro.url' => 'https://porro.test']);

    $this->key = SiteKey::generate()->withSite('01jabcdefghjkmnpqrstvwxyz0');
    app(SiteKeys::class)->store($this->key, 'Marlowe Studio', 'harbour-arts.org');
});

/**
 * Porro, faked: the disconnect call must be signed by the stored key.
 */
function porroDisconnects(SiteKey $key, int $status = 200): ArrayObject
{
    $seen = new ArrayObject(['disconnects' => 0, 'signed' => false]);

    Http::fake([
        'porro.test/api/v1/disconnect' => function (Sent $request) use ($seen, $key, $status) {
            $seen['disconnects']++;
            $headers = array_map(fn ($v) => $v[0], $request->headers());
            $seen['signed'] = signatureIsValid($headers, $request->body(), base64_decode($key->publicKey()), 'POST', '/api/v1/disconnect')
                && $request->header('X-Porro-Site')[0] === strtoupper((string) $key->siteId);

            return $status === 200 ? Http::response(['ok' => true]) : Http::response(['ok' => false, 'error' => 'Server error.'], $status);
        },
        '*' => Http::response(['ok' => false, 'error' => 'Invalid signature.'], 401),
    ]);

    return $seen;
}

it('tells Porro, then deletes the key from the site', function () {
    $porro = porroDisconnects($this->key);
    Cache::forever('porro:lock-hash', 'abc');

    $result = app(Disconnect::class)->disconnect();

    expect($result)->toBe(['workspace' => 'Marlowe Studio', 'host' => 'harbour-arts.org', 'told' => true])
        ->and($porro['disconnects'])->toBe(1)
        ->and($porro['signed'])->toBeTrue()
        ->and(DB::table('porro_settings')->where('key', 'site_key')->exists())->toBeFalse()
        ->and((new SiteKeys)->connected())->toBeFalse()
        // The next site it connects to gets the package list again.
        ->and(Cache::get('porro:lock-hash'))->toBeNull();
});

it('still deletes the key when Porro doesn’t answer, and says Porro wasn’t told', function () {
    $porro = porroDisconnects($this->key, 503);

    $result = app(Disconnect::class)->disconnect();

    expect($result['told'])->toBeFalse()
        ->and($porro['disconnects'])->toBeGreaterThan(0)
        ->and((new SiteKeys)->connected())->toBeFalse();
});

it('leaves a key from the environment alone', function () {
    $porro = porroDisconnects($this->key);
    config(['porro.key' => siteKey()['token']]);

    expect(app(Disconnect::class)->available())->toBeFalse()
        ->and(app(Disconnect::class)->disconnect())->toBeNull()
        ->and($porro['disconnects'])->toBe(0)
        ->and(DB::table('porro_settings')->where('key', 'site_key')->exists())->toBeTrue();

    $this->artisan('porro:disconnect', ['--force' => true])
        ->expectsOutputToContain('PORRO_KEY')
        ->assertFailed();
});

it('disconnects from the terminal, asking first', function () {
    $porro = porroDisconnects($this->key);

    $this->artisan('porro:disconnect')
        ->expectsConfirmation('Disconnect harbour-arts.org from Marlowe Studio?', 'no')
        ->assertSuccessful();

    expect($porro['disconnects'])->toBe(0)
        ->and((new SiteKeys)->connected())->toBeTrue();

    $this->artisan('porro:disconnect')
        ->expectsConfirmation('Disconnect harbour-arts.org from Marlowe Studio?', 'yes')
        ->expectsOutputToContain('Disconnected. Porro has been told.')
        ->assertSuccessful();

    expect($porro['disconnects'])->toBe(1)
        ->and((new SiteKeys)->connected())->toBeFalse();

    $this->artisan('porro:disconnect')->expectsOutputToContain('isn’t connected')->assertSuccessful();
});

it('won’t disconnect from a copy that isn’t the live site unless told it is', function () {
    $porro = porroDisconnects($this->key);
    $this->app['env'] = 'staging';

    $this->artisan('porro:disconnect', ['--force' => true])
        ->expectsOutputToContain('not the live site')
        ->assertFailed();

    expect($porro['disconnects'])->toBe(0)
        ->and((new SiteKeys)->connected())->toBeTrue();

    $this->artisan('porro:disconnect', ['--force' => true, '--live' => true])->assertSuccessful();

    expect($porro['disconnects'])->toBe(1)
        ->and((new SiteKeys)->connected())->toBeFalse();
});

it('deletes a stored key Porro turns away, and remembers that it happened', function () {
    Http::fake(['*' => Http::response(['ok' => false, 'error' => 'Invalid signature.'], 401)]);

    $result = app(Reporter::class)->send();

    $keys = new SiteKeys;

    expect($result->ok)->toBeFalse()
        ->and($keys->connected())->toBeFalse()
        ->and($keys->turnedAway())->toMatchArray(['workspace' => 'Marlowe Studio'])
        ->and($keys->turnedAway()['at'])->not->toBeNull();

    // Connecting again clears the note.
    $keys->store($this->key, 'Marlowe Studio', 'harbour-arts.org');

    expect((new SiteKeys)->turnedAway())->toBeNull();
});

it('keeps the key through a clock that’s out, a server error and a key from the environment', function (int $status, string $error) {
    Http::fake(['*' => Http::response(['ok' => false, 'error' => $error], $status)]);

    app(Reporter::class)->send();

    expect((new SiteKeys)->connected())->toBeTrue()
        ->and((new SiteKeys)->turnedAway())->toBeNull();
})->with([
    [401, 'Report timestamp outside the allowed window. Check the server clock.'],
    [500, 'Server error.'],
    [409, 'Report already received.'],
]);

it('never deletes a key from the environment, or one passed in, when Porro turns it away', function () {
    Http::fake(['*' => Http::response(['ok' => false, 'error' => 'Invalid signature.'], 401)]);

    // porro:connect trying a key the person pasted.
    app(Reporter::class)->send(siteKey()['token'], 'https://porro.test');

    expect((new SiteKeys)->connected())->toBeTrue();

    config(['porro.key' => siteKey()['token']]);
    app(Reporter::class)->send();

    expect(DB::table('porro_settings')->where('key', 'site_key')->exists())->toBeTrue()
        ->and((new SiteKeys)->turnedAway())->toBeNull();
});
