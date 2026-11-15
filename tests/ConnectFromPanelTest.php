<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as Sent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Porro\Core\SiteKey;
use Porro\Laravel\Connect\PanelConnect;
use Porro\Laravel\SiteKeys;

uses(RefreshDatabase::class);

const REQUEST_ID = '0196f3a2-7c41-7e0b-9d55-2f6a8c1e4b70';
const SITE_ID = '01jabcdefghjkmnpqrstvwxyz0';
const SITE = 'https://harbour-arts.org';
const NEXT = 'https://porro.test/connect/'.REQUEST_ID;

beforeEach(function () {
    $this->app->setBasePath(__DIR__.'/fixtures/app');
    $this->app['env'] = 'production';
    config(['porro.key' => null, 'porro.enabled' => null, 'app.url' => SITE]);

    Gate::define('viewPorro', fn (User $user) => true);
    Gate::define('connectPorro', fn (User $user) => $user->email === 'sam@harbour-arts.org');
});

function panelUser(int $id = 1, string $email = 'sam@harbour-arts.org'): User
{
    return (new User)->forceFill(['id' => $id, 'name' => 'Sam', 'email' => $email]);
}

/**
 * Porro, faked: it checks each connect call is signed by the public key
 * the site sent when it asked, as the real one does.
 *
 * @param  array<string, array{0: int, 1: array<string, mixed>}>  $answers  for "start", "complete" and "report"
 */
function fakePorro(array|ArrayObject $answers = []): ArrayObject
{
    $porro = new ArrayObject(['public_key' => null, 'starts' => [], 'completes' => [], 'reports' => [], 'unsigned' => 0]);

    $signed = function (Sent $request, string $path) use ($porro): bool {
        $headers = array_map(fn ($v) => $v[0], $request->headers());
        $ok = $porro['public_key'] !== null && signatureIsValid($headers, $request->body(), base64_decode($porro['public_key']), 'POST', $path);
        $porro['unsigned'] += $ok ? 0 : 1;

        return $ok;
    };

    Http::fake([
        'porro.test/api/v1/connect' => function (Sent $request) use ($porro, $answers, $signed) {
            $porro['starts'][] = $request;
            $porro['public_key'] = $request['public_key'];

            if (! $signed($request, '/api/v1/connect') || $request->hasHeader('X-Porro-Site')) {
                return Http::response(['ok' => false, 'error' => 'Invalid signature.'], 401);
            }

            [$status, $body] = $answers['start'] ?? [201, ['ok' => true, 'id' => REQUEST_ID, 'approve_url' => NEXT, 'expires_at' => now()->addMinutes(10)->toIso8601String()]];

            return Http::response($body, $status);
        },
        'porro.test/api/v1/connect/*/complete' => function (Sent $request) use ($porro, $answers, $signed) {
            $porro['completes'][] = $request;

            if (! $signed($request, '/api/v1/connect/'.REQUEST_ID.'/complete')) {
                return Http::response(['ok' => false, 'error' => 'Invalid signature.'], 401);
            }

            if ($request['grant'] !== 'the-grant') {
                return Http::response(['ok' => false, 'error' => 'That grant doesn’t match this request.'], 403);
            }

            [$status, $body] = $answers['complete'] ?? [200, ['ok' => true, 'site' => SITE_ID, 'host' => 'harbour-arts.org', 'workspace' => 'Marlowe Studio']];

            return Http::response($body, $status);
        },
        'porro.test/api/v1/reports' => function (Sent $request) use ($porro) {
            $porro['reports'][] = $request;

            [$status, $body] = $answers['report'] ?? [202, ['ok' => true]];

            return Http::response($body, $status);
        },
        '*' => Http::response([], 200),
    ]);

    return $porro;
}

function pressConnect($test, ?User $user = null, array $headers = [], string $site = SITE): TestResponse
{
    // Production checks the CSRF token, as it will for the real button.
    return $test->actingAs($user ?? panelUser())->withSession(['_token' => 'csrf-token'])->withHeaders($headers)->post($site.'/porro/connect', ['_token' => 'csrf-token']);
}

function comeBack($test, array $query = [], ?User $user = null): TestResponse
{
    $query += ['porro_request' => REQUEST_ID, 'porro_grant' => 'the-grant', 'porro_next' => NEXT];

    return $test->actingAs($user ?? panelUser())->get(SITE.'/porro/connect?'.http_build_query($query));
}

describe('the round trip', function () {
    it('asks Porro, sends the browser to approve, then finishes and keeps the key', function () {
        $porro = fakePorro();

        pressConnect($this)->assertRedirect(NEXT);

        $asked = $porro['starts'][0];

        expect($porro['starts'])->toHaveCount(1)
            ->and($asked['address'])->toBe(SITE)
            ->and($asked['return_url'])->toBe(SITE.'/porro/connect')
            ->and($asked['connector']['name'])->toBe('porro/laravel-connector')
            ->and($asked['platform'])->toMatchArray(['name' => 'laravel', 'environment' => 'production'])
            ->and($asked['php']['version'])->toBe(PHP_VERSION)
            ->and(app(SiteKeys::class)->token())->toBeNull();

        // Not the site's key yet: nothing is stored until Porro accepts the grant.
        expect(DB::table('porro_settings')->count())->toBe(0);

        comeBack($this)->assertRedirect(NEXT);

        $token = (new SiteKeys)->token();
        $key = SiteKey::parse((string) $token);

        expect($porro['completes'])->toHaveCount(1)
            ->and($porro['unsigned'])->toBe(0)
            ->and($key->siteId)->toBe(strtoupper(SITE_ID))
            ->and($key->publicKey())->toBe($porro['public_key'])
            ->and((new SiteKeys)->stored())->toMatchArray(['workspace' => 'Marlowe Studio', 'host' => 'harbour-arts.org'])
            ->and((new SiteKeys)->source())->toBe(SiteKeys::STORED);

        // The first report, signed as the site now.
        $report = $porro['reports'][0];

        expect($porro['reports'])->toHaveCount(1)
            ->and($report['trigger'])->toBe('connect')
            ->and($report->header('X-Porro-Site')[0])->toBe(strtoupper(SITE_ID))
            ->and(signatureIsValid(array_map(fn ($v) => $v[0], $report->headers()), $report->body(), base64_decode($porro['public_key'])))->toBeTrue();
    });

    it('keeps the seed out of the session and out of anything readable', function () {
        fakePorro();

        pressConnect($this);

        $started = session('porro.connect');
        // Waiting in Porro's own table, not the session.
        $cached = DB::table('porro_cache')->where('key', 'like', '%porro:connect:%')->pluck('value', 'key');

        expect(array_keys($started))->toBe(['state', 'id'])
            ->and($started['id'])->toBe(REQUEST_ID)
            ->and($cached)->toHaveCount(1)
            // Filed under a hash of the state, and encrypted.
            ->and($cached->keys()->first())->not->toContain($started['state'])
            ->and($cached->first())->not->toContain(REQUEST_ID);
    });

    it('comes back to the page when Porro’s own page is done with it', function () {
        fakePorro();
        pressConnect($this);
        comeBack($this);

        // Porro sends the person back to the return URL, without the grant.
        $this->actingAs(panelUser())->get(SITE.'/porro/connect')->assertRedirect(SITE.'/porro');

        // And the link with the grant, opened again, does nothing new.
        $porro = fakePorro();
        comeBack($this)->assertRedirect(NEXT);

        expect($porro['completes'])->toBeEmpty();
    });

    it('only goes on to the Porro it’s set up for', function (string $next) {
        fakePorro();
        pressConnect($this);

        comeBack($this, ['porro_next' => $next])
            ->assertRedirect(SITE.'/porro')
            ->assertSessionHas('success');

        expect((new SiteKeys)->token())->not->toBeNull();
    })->with(['https://kestrel-legal.com/connect/'.REQUEST_ID, 'https://porro.test.kestrel-legal.com/', 'javascript:alert(1)', '']);

    it('connects even when the first report doesn’t get through', function () {
        fakePorro(['report' => [503, []]]);
        pressConnect($this);

        comeBack($this)->assertRedirect(NEXT);

        expect((new SiteKeys)->token())->not->toBeNull();
    });
});

describe('who can do it', function () {
    it('needs the connectPorro gate, not just viewing', function () {
        $porro = fakePorro();
        $viewer = panelUser(2, 'viewer@harbour-arts.org');

        $this->actingAs($viewer)->get(SITE.'/porro')->assertOk();
        pressConnect($this, $viewer)->assertForbidden();
        comeBack($this, [], $viewer)->assertForbidden();

        expect($porro['starts'])->toBeEmpty()
            ->and($porro['completes'])->toBeEmpty();
    });

    it('is closed to everyone until the gate is defined, and to guests always', function () {
        $porro = fakePorro();

        $this->withSession(['_token' => 'csrf-token'])->post(SITE.'/porro/connect', ['_token' => 'csrf-token'])->assertForbidden();
        $this->get(SITE.'/porro/connect?porro_request='.REQUEST_ID.'&porro_grant=the-grant')->assertForbidden();

        Gate::define('connectPorro', fn (User $user) => true);
        (fn () => $this->abilities = array_diff_key($this->abilities, ['connectPorro' => true]))->call(Gate::getFacadeRoot());

        pressConnect($this)->assertForbidden();

        expect($porro['starts'])->toBeEmpty();
    });

    it('starts with a POST behind the web middleware, so it has CSRF protection', function () {
        $start = Route::getRoutes()->getByName('porro.connect.start');
        $return = Route::getRoutes()->getByName('porro.connect.return');

        expect($start->methods())->toBe(['POST'])
            ->and($start->gatherMiddleware())->toContain('web')
            ->and($return->methods())->toContain('GET')
            ->and($return->gatherMiddleware())->toContain('web');

        // Without the token, nothing starts.
        $porro = fakePorro();
        $this->actingAs(panelUser())->post(SITE.'/porro/connect')->assertStatus(419);

        expect($porro['starts'])->toBeEmpty();
    });

    it('only finishes a request the same person started in the same session', function () {
        $porro = fakePorro();
        pressConnect($this);

        // Someone else, signed in to the same browser session.
        Gate::define('connectPorro', fn (User $user) => true);
        comeBack($this, [], panelUser(7, 'alex@harbour-arts.org'))
            ->assertRedirect(SITE.'/porro')
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'different request'));

        expect($porro['completes'])->toBeEmpty()
            ->and((new SiteKeys)->token())->toBeNull();
    });

    it('won’t finish a request this session didn’t start', function () {
        $porro = fakePorro();

        // A link from someone else, with a grant for their own request.
        comeBack($this)
            ->assertRedirect(SITE.'/porro')
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'no request to connect waiting'));

        pressConnect($this);

        comeBack($this, ['porro_request' => '0196f3a2-0000-7000-8000-000000000000'])
            ->assertRedirect(SITE.'/porro')
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'different request'));

        expect($porro['completes'])->toBeEmpty()
            ->and((new SiteKeys)->token())->toBeNull();

        // The real one still works afterwards.
        comeBack($this)->assertRedirect(NEXT);
    });
});

describe('signed in or not at all', function () {
    it('never lets a guest connect, even through gates that allow guests', function () {
        $porro = fakePorro();
        Gate::define('viewPorro', fn (?User $user) => true);
        Gate::define('connectPorro', fn (?User $user) => true);

        $this->withSession(['_token' => 'csrf-token'])->post(SITE.'/porro/connect', ['_token' => 'csrf-token'])->assertForbidden();
        $this->get(SITE.'/porro/connect?porro_request='.REQUEST_ID.'&porro_grant=the-grant')->assertForbidden();

        // Nor locally, where the page itself is open to everyone.
        $this->app['env'] = 'local';
        $this->withSession(['_token' => 'csrf-token'])->post(SITE.'/porro/connect', ['_token' => 'csrf-token'])->assertForbidden();

        expect($porro['starts'])->toBeEmpty()
            ->and($porro['completes'])->toBeEmpty()
            ->and(session('porro.connect'))->toBeNull();
    });

    it('never ties a request to a user with no identifier', function () {
        $porro = fakePorro();
        Gate::define('connectPorro', fn (User $user) => true);

        pressConnect($this, (new User)->forceFill(['name' => 'Nobody', 'email' => 'sam@harbour-arts.org']))->assertForbidden();

        expect($porro['starts'])->toBeEmpty();
    });
});

describe('when the key can’t be saved', function () {
    it('tries again when the database blinks, and connects', function () {
        fakePorro();
        pressConnect($this);

        $this->app->scoped(SiteKeys::class, fn () => new class extends SiteKeys
        {
            public int $tries = 0;

            public function store(SiteKey $key, ?string $workspace = null, ?string $host = null): void
            {
                if (++$this->tries < 3) {
                    throw new RuntimeException('database is locked');
                }

                parent::store($key, $workspace, $host);
            }
        });
        $this->app->forgetScopedInstances();

        comeBack($this)->assertRedirect(NEXT);

        expect(app(SiteKeys::class)->tries)->toBe(3)
            ->and((new SiteKeys)->token())->not->toBeNull();
    });

    it('says the site isn’t connected when it never saves, without the error’s text', function () {
        $porro = fakePorro();
        pressConnect($this);

        $this->app->scoped(SiteKeys::class, fn () => new class extends SiteKeys
        {
            public function store(SiteKey $key, ?string $workspace = null, ?string $host = null): void
            {
                throw new RuntimeException('could not write '.$key->token());
            }
        });
        $this->app->forgetScopedInstances();

        comeBack($this)
            ->assertRedirect(SITE.'/porro')
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'couldn’t be saved') && ! str_contains($error, 'porro_'));

        expect((new SiteKeys)->token())->toBeNull()
            ->and($porro['reports'])->toBeEmpty();
    });
});

describe('when it can’t connect', function () {
    it('says so when the request was turned down', function () {
        $porro = fakePorro(['complete' => [403, ['ok' => false, 'error' => 'The request was turned down in the browser.']]]);
        pressConnect($this);

        comeBack($this)
            ->assertRedirect(SITE.'/porro')
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'turned down'));

        expect((new SiteKeys)->token())->toBeNull()
            ->and(session('porro.connect'))->toBeNull()
            ->and($porro['reports'])->toBeEmpty();
    });

    it('says so when the request ran out of time here', function () {
        $porro = fakePorro();
        pressConnect($this);

        $this->travel(PanelConnect::MINUTES + 1)->minutes();

        comeBack($this)
            ->assertRedirect(SITE.'/porro')
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'ran out of time'));

        expect($porro['completes'])->toBeEmpty()
            ->and((new SiteKeys)->token())->toBeNull();
    });

    it('says so when Porro says it expired or was already used', function () {
        fakePorro(['complete' => [410, ['ok' => false, 'error' => 'This request has expired or was already used. Start again from the control panel.']]]);
        pressConnect($this);

        comeBack($this)->assertSessionHas('error', fn ($error) => str_contains($error, 'ran out of time or was already used'));

        expect((new SiteKeys)->token())->toBeNull();
    });

    it('says so when the grant isn’t this request’s', function () {
        fakePorro();
        pressConnect($this);

        comeBack($this, ['porro_grant' => 'someone-elses-grant'])
            ->assertRedirect(SITE.'/porro')
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'didn’t accept that approval') && ! str_contains($error, 'someone-elses-grant'));

        expect((new SiteKeys)->token())->toBeNull();

        comeBack($this, ['porro_grant' => ''])->assertSessionHas('error');
    });

    it('says so when Porro can’t be reached to start', function () {
        $porro = fakePorro(['start' => [503, []]]);

        pressConnect($this)
            ->assertRedirect(SITE.'/porro')
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'Couldn’t reach Porro'));

        expect(session('porro.connect'))->toBeNull()
            ->and($porro['starts'])->toHaveCount(2);
    });

    it('lets the same link try again when Porro can’t be reached to finish', function () {
        $answers = new ArrayObject(['complete' => [502, []]]);
        $porro = fakePorro($answers);
        pressConnect($this);

        comeBack($this)->assertSessionHas('error', fn ($error) => str_contains($error, 'Couldn’t reach Porro'));

        expect((new SiteKeys)->token())->toBeNull();

        // Porro is back: the key pair made at the start is still waiting.
        unset($answers['complete']);

        comeBack($this)->assertRedirect(NEXT);

        expect($porro['completes'])->toHaveCount(2)
            ->and(SiteKey::parse((string) (new SiteKeys)->token())->publicKey())->toBe($porro['public_key']);
    });

    it('passes on what Porro says is wrong with the site’s details', function () {
        fakePorro(['start' => [422, ['ok' => false, 'error' => 'The return URL must be on the site’s own address, over https.']]]);

        pressConnect($this)->assertSessionHas('error', fn ($error) => str_contains($error, 'didn’t accept this site’s details') && str_contains($error, 'over https'));
    });

    it('explains a server clock that’s out', function () {
        fakePorro(['start' => [401, ['ok' => false, 'error' => 'Request timestamp outside the allowed window. Check the server clock.']]]);

        pressConnect($this)->assertSessionHas('error', fn ($error) => str_contains($error, 'clock'));
    });

    it('doesn’t start when the site already has a key in its environment', function () {
        $porro = fakePorro();
        config(['porro.key' => siteKey()['token']]);

        pressConnect($this)
            ->assertRedirect(SITE.'/porro')
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'PORRO_KEY'));

        expect($porro['starts'])->toBeEmpty();
    });

    it('doesn’t finish when a key was put in the environment meanwhile', function () {
        $porro = fakePorro();
        pressConnect($this);
        config(['porro.key' => siteKey()['token']]);

        comeBack($this)->assertSessionHas('error', fn ($error) => str_contains($error, 'PORRO_KEY'));

        expect($porro['completes'])->toBeEmpty()
            ->and(DB::table('porro_settings')->count())->toBe(0);
    });

    it('doesn’t connect a copy that isn’t the live site', function (string $env) {
        $porro = fakePorro();
        $this->app['env'] = $env;
        // Even one that's been told to report.
        config(['porro.enabled' => true]);

        pressConnect($this)
            ->assertRedirect(SITE.'/porro')
            ->assertSessionHas('error', fn ($error) => str_contains($error, "“{$env}”") && str_contains($error, 'live site'));

        expect($porro['starts'])->toBeEmpty();
    })->with(['local', 'staging']);

    it('doesn’t connect when reporting is switched off', function () {
        $porro = fakePorro();
        config(['porro.enabled' => false]);

        pressConnect($this)->assertSessionHas('error', fn ($error) => str_contains($error, 'PORRO_ENABLED=false'));

        expect($porro['starts'])->toBeEmpty();
    });

    it('asks for the migration rather than start something it can’t keep', function () {
        $porro = fakePorro();
        Schema::drop('porro_settings');

        pressConnect($this)->assertSessionHas('error', fn ($error) => str_contains($error, 'php artisan migrate'));

        expect($porro['starts'])->toBeEmpty();
    });
});

describe('behind a proxy', function () {
    it('explains instead of asking for http when the browser is on https', function (array $headers) {
        $porro = fakePorro();

        pressConnect($this, null, $headers, 'http://harbour-arts.org')
            ->assertRedirect()
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'https') && str_contains($error, 'proxy'));

        expect($porro['starts'])->toBeEmpty();
    })->with([
        'the browser’s origin' => [['Origin' => 'https://harbour-arts.org']],
        'the referring page' => [['Referer' => 'https://harbour-arts.org/porro']],
        'an untrusted forwarded header' => [['X-Forwarded-Proto' => 'https']],
        'Cloudflare' => [['CF-Visitor' => '{"scheme":"https"}']],
        'APP_URL alone' => [[]],
    ]);

    it('connects a site that really is plain http', function () {
        $porro = fakePorro();
        config(['app.url' => 'http://harbour-arts.org']);

        pressConnect($this, null, ['Origin' => 'http://harbour-arts.org'], 'http://harbour-arts.org')->assertRedirect(NEXT);

        expect($porro['starts'][0]['address'])->toBe('http://harbour-arts.org')
            ->and($porro['starts'][0]['return_url'])->toBe('http://harbour-arts.org/porro/connect');
    });

    it('uses the host the browser is on, not APP_URL', function () {
        $porro = fakePorro();

        pressConnect($this, null, [], 'https://www.harbour-arts.org')->assertRedirect(NEXT);

        expect($porro['starts'][0]['address'])->toBe('https://www.harbour-arts.org')
            ->and($porro['starts'][0]['return_url'])->toBe('https://www.harbour-arts.org/porro/connect');
    });
});

describe('what the panel should show', function () {
    it('knows each state', function () {
        $connect = fn () => app(PanelConnect::class)->state();

        expect($connect())->toBe(PanelConnect::READY);

        config(['porro.enabled' => false]);
        expect($connect())->toBe(PanelConnect::OFF);
        config(['porro.enabled' => null]);

        app(SiteKeys::class)->store(SiteKey::generate()->withSite(SITE_ID));
        expect($connect())->toBe(PanelConnect::CONNECTED);

        $this->app['env'] = 'local';
        expect($connect())->toBe(PanelConnect::DEVELOPMENT);

        config(['porro.key' => siteKey()['token']]);
        expect($connect())->toBe(PanelConnect::ENV);
    });

    it('has no button on the /porro page yet', function () {
        $this->actingAs(panelUser())->get(SITE.'/porro')->assertOk()->assertDontSee('Connect to Porro');
    });
});
