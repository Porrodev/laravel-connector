<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Porro\Core\Activity\AbuseIpdb;
use Porro\Core\Activity\Cloudflare;
use Porro\Core\Activity\IpDetails;
use Porro\Core\Activity\Recorder;
use Porro\Laravel\Activity\Activity;
use Porro\Laravel\Activity\CloudflareMode;
use Porro\Laravel\Activity\Confirm;
use Porro\Laravel\SiteHealth;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake([
        IpDetails::TOR_URL => Http::response("185.220.101.4\n"),
        'www.spamhaus.org/*' => Http::response("{\"cidr\":\"91.92.240.0/22\",\"sblid\":\"SBL1\"}\n"),
        AbuseIpdb::URL.'*' => Http::response(['data' => ['abuseConfidenceScore' => 100, 'totalReports' => 2048, 'isp' => 'Example Hosting']]),
        '*' => Http::response('', 500),
    ]);
    $this->app->instance('porro.reverse-dns', fn () => null);
    Gate::define('viewPorro', fn ($user) => true);
    $this->actingAs((new User)->forceFill(['id' => 1, 'name' => 'Ops']));

    $recorder = app(Activity::class)->recorder();

    foreach (['/.env', '/.git/config', '/wp-login.php'] as $path) {
        $recorder->record('185.220.101.4', Recorder::PROBE, 'GET', $path, 404, country: 'DE', userAgent: 'Mozilla/5.0 (compatible; Googlebot/2.1)');
    }
});

it('sums up its requests: what it was after, how it reached the server, and which host', function () {
    $recorder = app(Activity::class)->recorder();

    foreach (range(1, 4) as $i) {
        $recorder->record('45.148.10.92', Recorder::PROBE, 'GET', '/containers/json', 404, userAgent: 'zgrab/0.x', category: 'infrastructure', host: 'www.example.com', proxied: false);
    }
    $recorder->turnedAway('45.148.10.92', 'GET', '/images/json', 'example.com', 'zgrab/0.x', true);

    $this->get('/porro/activity/45.148.10.92')
        ->assertOk()
        ->assertSee('Exposed services: Docker, MLflow, cloud metadata ×4')
        ->assertSee('1 turned away by the block')
        ->assertSee('straight to the server ×4')
        ->assertSee('through Cloudflare ×1')
        ->assertSee('www.example.com ×4')
        ->assertSee('/containers/json')
        ->assertSee('Blocking at Cloudflare won’t stop those')
        ->assertSee('zgrab/0.x');
});

it('shows an IP that was recorded but never flagged', function () {
    app(Activity::class)->recorder()->record('45.148.10.93', Recorder::NOT_FOUND, 'GET', '/missing', 404);

    $this->get('/porro/activity/45.148.10.93')
        ->assertOk()
        ->assertSee('not flagged')
        ->assertSee('Recorded, but under the thresholds')
        ->assertSee('/missing');
});

it('opens from the Activity table', function () {
    $this->get('/porro/activity')->assertSee(route('porro.ip', '185.220.101.4'), false);
});

it('shows who it is, what it did and what to do about it', function () {
    $this->get('/porro/activity/185.220.101.4')
        ->assertOk()
        ->assertSee('185.220.101.4')
        ->assertSee('🇩🇪 DE')
        ->assertSee('Tor exit')
        ->assertSee('Fake Googlebot')
        ->assertSee('Pretending to be Googlebot')
        ->assertSee('real Googlebot addresses resolve to googlebot.com')
        ->assertSee('Blocking it won’t affect search')
        ->assertSee('/wp-login.php')
        ->assertSee('Paths it asked for')
        ->assertSee('Mozilla/5.0 (compatible; Googlebot/2.1)')
        ->assertSee('Block permanently')
        ->assertSee('Tor exit list · Spamhaus DROP');
});

it('uses Cloudflare Intel when it can, and Radar once Intel is refused', function () {
    config(['porro.blocklist.cloudflare_token' => 'token']);
    Activity::store()->forever(CloudflareMode::STATE, ['connection' => ['account' => 'a1', 'zone_name' => 'example.com']]);
    $intelAllowed = true;
    $this->app->instance(Cloudflare::class, new Cloudflare('token', function (string $method, string $url) use (&$intelAllowed) {
        if (str_contains($url, '/intel/ip')) {
            return $intelAllowed
                ? [200, json_encode(['success' => true, 'result' => ['belongs_to_ref' => ['value' => 64500, 'description' => 'Example Hosting GmbH', 'type' => 'hosting_provider', 'country' => 'DE'], 'risk_types' => [['name' => 'Anonymizer']]]])]
                : [403, json_encode(['success' => false, 'errors' => [['message' => 'Unauthorized to access requested resource']]])];
        }

        return [200, json_encode(['success' => true, 'result' => ['ip' => ['asn' => 64501, 'asnOrgName' => 'Other Hosting Ltd', 'location' => 'NL']]])];
    }, fn () => null));

    $this->get('/porro/activity/185.220.101.4')
        ->assertSee('Example Hosting GmbH · AS64500')
        ->assertSee('Hosting provider')
        ->assertSee('Cloudflare: Anonymizer')
        ->assertSee('Block 185.220.101.0/24')
        ->assertSee('Cloudflare IP Intelligence');

    Cache::flush();
    Activity::store()->forever(CloudflareMode::STATE, ['connection' => ['account' => 'a1', 'zone_name' => 'example.com']]);
    $intelAllowed = false;

    $this->get('/porro/activity/45.148.10.92')->assertSee('Other Hosting Ltd · AS64501')->assertSee('Cloudflare Radar')->assertDontSee('Block 45.148.10.0/24');

    expect(Cache::get('porro:cloudflare:no-intel'))->toBeTrue();
});

it('checks AbuseIPDB only when asked, and only with a key', function () {
    $this->get('/porro/activity/185.220.101.4')->assertSee('Set PORRO_ABUSEIPDB_KEY')->assertDontSee('Check on AbuseIPDB');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'abuseipdb'));

    config(['porro.activity.abuseipdb_key' => 'k3y']);
    $this->get('/porro/activity/185.220.101.4')->assertSee('Check on AbuseIPDB')->assertSee('Sends this IP to AbuseIPDB');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'abuseipdb'));

    $this->from('/porro/activity/185.220.101.4')->post('/porro/activity/185.220.101.4/reputation')->assertRedirect('/porro/activity/185.220.101.4');
    $this->get('/porro/activity/185.220.101.4')->assertSee('100%')->assertSee('2,048');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'abuseipdb') && $request->header('Key') === ['k3y']);
});

it('needs managePorroBlocklist to send an IP to AbuseIPDB', function () {
    config(['porro.activity.abuseipdb_key' => 'k3y']);
    Gate::define('managePorroBlocklist', fn ($user) => false);

    $this->post('/porro/activity/185.220.101.4/reputation')->assertForbidden();
    $this->get('/porro/activity/185.220.101.4')->assertDontSee('Check on AbuseIPDB')->assertDontSee('Block permanently');
});

it('says plainly when a crawler is real, and what blocking it would mean', function () {
    // With crawler protection off, so a real Googlebot can be recorded.
    config(['porro.blocklist.protect.crawlers' => false]);
    $this->app->forgetInstance(Activity::class);
    $this->app->instance('porro.reverse-dns', fn () => 'crawl-66-249-66-1.googlebot.com');
    // Googlebot following old links to scanner-looking paths, enough to be flagged.
    foreach (['/.env.example', '/backup.zip', '/wp-login.php'] as $path) {
        app(Activity::class)->recorder()->record('66.249.66.1', Recorder::PROBE, 'GET', $path, 404, userAgent: 'Mozilla/5.0 (compatible; Googlebot/2.1)');
    }

    $this->get('/porro/activity/66.249.66.1')
        ->assertSee('Real Googlebot')
        ->assertSee('it resolves to crawl-66-249-66-1.googlebot.com')
        ->assertSee('Blocking it would stop the site being crawled')
        ->assertDontSee('Fake Googlebot');
});

it('never records a protected crawler, and offers no way to block it', function () {
    foreach (['/.env.example', '/backup.zip', '/wp-login.php'] as $path) {
        app(Activity::class)->recorder()->record('66.249.66.1', Recorder::PROBE, 'GET', $path, 404);
    }

    $this->get('/porro/activity/66.249.66.1')
        ->assertSee('always protected: Search engine crawlers')
        ->assertSee('Nothing recorded from this IP in the last 48 hours')
        ->assertDontSee('Block permanently');
});

it('says when an IP did nothing here, and 404s on nonsense', function () {
    $this->get('/porro/activity/45.148.10.92')->assertOk()->assertSee('Nothing recorded from this IP in the last 48 hours');
    $this->get('/porro/activity/1.2.3')->assertNotFound();
});

it('is closed in production without the gate, like the rest of /porro', function () {
    $this->app['env'] = 'production';
    Gate::define('viewPorro', fn ($user) => false);

    $this->get('/porro/activity/185.220.101.4')->assertForbidden();
});

it('ranks the activity table by what’s known, scanners last', function () {
    $recorder = app(Activity::class)->recorder();

    // More probes than the Tor exit (4 against 3), but nothing known about it.
    for ($i = 0; $i < 4; $i++) {
        $recorder->record('103.75.190.11', Recorder::PROBE, 'GET', '/.env', 404);
    }

    // The busiest of all, but a research scanner once its details are looked up.
    $this->app->instance('porro.reverse-dns', fn ($ip) => $ip === '167.94.138.5' ? 'scan-12.censys-scanner.com' : null);
    for ($i = 0; $i < 20; $i++) {
        $recorder->record('167.94.138.5', Recorder::PROBE, 'GET', '/.git/config', 404);
    }
    $this->get('/porro/activity/167.94.138.5')->assertSee('Research scanner');
    app(SiteHealth::class)->forget();

    $html = $this->get('/porro/activity')->assertSee('Scanner, low risk')->assertSee('Tor')->getContent();

    // Tor: 3 probes × 5 × 1.5 = 22.5, above 4 plain probes (20) despite fewer.
    // The scanner has the most (100) and still goes last.
    expect(strpos($html, '185.220.101.4'))->toBeLessThan(strpos($html, '103.75.190.11'))
        ->and(strpos($html, '103.75.190.11'))->toBeLessThan(strpos($html, '167.94.138.5'));
});

it('asks in a dialog before every block and safelist, saying what will happen', function () {
    $html = $this->get('/porro/activity/185.220.101.4')->getContent();

    expect(substr_count($html, 'data-porro-confirm="'))->toBe(3)
        ->and($html)->toContain('<dialog id="porro-confirm"')
        ->toContain('data-porro-ok')
        ->toContain(e(Confirm::attribute(Confirm::block('185.220.101.4'))))
        ->toContain(e(Confirm::attribute(Confirm::block('185.220.101.4', 7))))
        ->toContain(e(Confirm::attribute(Confirm::safelist('185.220.101.4'))))
        ->toContain('window.porroConfirm');

    expect(substr_count($this->get('/porro/activity')->getContent(), 'data-porro-confirm="'))->toBe(3);

    // Safelist acts straight away once confirmed.
    $this->post('/porro/blocking/safelist', ['ip' => '185.220.101.4', 'label' => 'From the IP details page'])->assertSessionHas('success');
    $this->get('/porro/activity/185.220.101.4')->assertSee('safelisted')->assertDontSee('data-porro-confirm="', false);
});

it('says how much a range block covers, how long it lasts, and names the button for it', function () {
    expect(Confirm::block('185.220.101.0/24', 7))->toMatchArray([
        'title' => 'Block 185.220.101.0/24 for 7 days?',
        'button' => 'Block range for 7 days',
        'danger' => true,
    ])
        ->and(Confirm::block('185.220.101.0/24', 7)['body'])->toContain('the 256 addresses in 185.220.101.0/24')->toContain('including any real visitors')->toContain('lifted automatically')
        ->and(Confirm::block('185.220.101.4'))->toMatchArray(['title' => 'Block 185.220.101.4 permanently?', 'button' => 'Block permanently'])
        ->and(Confirm::block('185.220.101.4')['body'])->toContain('until someone removes it')
        ->and(Confirm::block('2001:db8::/48')['body'])->toContain('the many addresses')
        ->and(Confirm::safelist('81.2.69.142'))->toMatchArray(['button' => 'Safelist', 'danger' => false]);
});
