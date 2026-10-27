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
        ->assertSee('Block permanently')
        ->assertSee('Tor exit list · Spamhaus DROP');
});

it('uses Cloudflare Intel when it can, and Radar once Intel is refused', function () {
    config(['porro.blocklist.cloudflare_token' => 'token']);
    Cache::forever(CloudflareMode::STATE, ['connection' => ['account' => 'a1', 'zone_name' => 'example.com']]);
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
    Cache::forever(CloudflareMode::STATE, ['connection' => ['account' => 'a1', 'zone_name' => 'example.com']]);
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

it('says when an IP did nothing here, and 404s on nonsense', function () {
    $this->get('/porro/activity/45.148.10.92')->assertOk()->assertSee('Nothing flagged from this IP in the last 48 hours');
    $this->get('/porro/activity/1.2.3')->assertNotFound();
});

it('is closed in production without the gate, like the rest of /porro', function () {
    $this->app['env'] = 'production';
    Gate::define('viewPorro', fn ($user) => false);

    $this->get('/porro/activity/185.220.101.4')->assertForbidden();
});
