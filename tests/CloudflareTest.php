<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Porro\Core\Activity\Cloudflare;
use Porro\Core\Activity\Entry;
use Porro\Laravel\Activity\Activity;
use Porro\Laravel\Activity\CloudflareMode;

uses(RefreshDatabase::class);

/**
 * Cloudflare's API in memory: one zone, the list and its items, the
 * zone's custom rules, and instant bulk operations.
 */
class TinyCloudflare
{
    public array $items = [];

    public array $rules = [];

    public bool $listed = false;

    public int $calls = 0;

    public function http(): Closure
    {
        return function (string $method, string $url, array $headers, ?string $body) {
            $this->calls++;
            $path = (string) parse_url(substr($url, strlen(Cloudflare::API)), PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $data = json_decode((string) $body, true);
            $ok = fn ($result) => [200, json_encode(['success' => true, 'result' => $result, 'result_info' => []])];

            return match (true) {
                $path === '/user/tokens/verify' => $ok(['status' => 'active']),
                $path === '/zones' => $ok(($query['name'] ?? '') === 'dogearbooks.example' ? [['id' => 'z1', 'name' => 'dogearbooks.example', 'account' => ['id' => 'a1']]] : []),
                $path === '/accounts/a1/rules/lists' && $method === 'GET' => $ok($this->listed ? [['id' => 'l1', 'name' => 'porro_blocklist', 'kind' => 'ip']] : []),
                $path === '/accounts/a1/rules/lists' => $ok(['id' => 'l1', 'name' => ($this->listed = true) ? 'porro_blocklist' : '', 'kind' => 'ip']),
                $path === '/accounts/a1/rules/lists/l1/items' && $method === 'GET' => $ok($this->items),
                $path === '/accounts/a1/rules/lists/l1/items' && $method === 'POST' => $ok(['operation_id' => 'op'.count($this->items += array_combine(array_column($data, 'ip'), array_map(fn ($i) => $i + ['id' => $i['ip']], $data)))]),
                $path === '/accounts/a1/rules/lists/l1/items' && $method === 'DELETE' => $ok(['operation_id' => 'op'.count($this->items = array_diff_key($this->items, array_flip(array_column($data['items'], 'id'))))]),
                str_starts_with($path, '/accounts/a1/rules/lists/bulk_operations/') => $ok(['status' => 'completed']),
                $path === '/zones/z1/rulesets/rs1/rules/r1' && $method === 'DELETE' => $ok(['id' => 'rs1', 'rules' => $this->rules = []]),
                $path === '/accounts/a1/rules/lists/l1' && $method === 'DELETE' => $ok(['id' => 'l1', 'listed' => $this->listed = false]),
                str_ends_with($path, '/entrypoint') => $this->rules ? $ok(['id' => 'rs1', 'rules' => $this->rules]) : [404, json_encode(['success' => false, 'errors' => [['message' => 'not found']]])],
                $path === '/zones/z1/rulesets' => $ok(['id' => 'rs1', 'rules' => $this->rules = [$data['rules'][0] + ['id' => 'r1']]]),
                default => [404, json_encode(['success' => false, 'errors' => [['message' => "no route {$method} {$path}"]]])],
            };
        };
    }

    public function ips(): array
    {
        $ips = array_keys($this->items);
        sort($ips);

        return $ips;
    }
}

beforeEach(function () {
    $this->cloudflare = new TinyCloudflare;
    $this->app->instance(Cloudflare::class, new Cloudflare('token', $this->cloudflare->http(), fn () => null));
    config(['porro.blocklist.cloudflare_token' => 'token', 'app.url' => 'https://www.dogearbooks.example']);
    $this->app['env'] = 'production';
});

function block(string $ip, ?int $expires = null): void
{
    DB::table('porro_blocklist')->insert((new Entry($ip, 'Scanner', Entry::MANUAL, time(), $expires))->toArray());
}

it('needs a token', function () {
    config(['porro.blocklist.cloudflare_token' => null]);

    $this->artisan('porro:cloudflare', ['action' => 'connect'])->expectsOutputToContain('Set PORRO_CLOUDFLARE_TOKEN')->assertFailed();
});

it('connects to the zone serving the site and syncs the blocklist', function () {
    block('185.220.101.4');

    $this->artisan('porro:cloudflare', ['action' => 'connect'])
        ->expectsOutputToContain('Connected to dogearbooks.example')
        ->expectsOutputToContain('1 added')
        ->assertSuccessful();

    $siteId = app(CloudflareMode::class)->siteId();

    expect($this->cloudflare->ips())->toBe(['185.220.101.4'])
        ->and($this->cloudflare->items['185.220.101.4']['comment'])->toBe("porro:{$siteId}:Scanner")
        ->and($this->cloudflare->rules[0]['expression'])->toBe('ip.src in $porro_blocklist');

    $this->artisan('porro:cloudflare')->expectsOutputToContain('dogearbooks.example')->assertSuccessful();
});

it('never syncs from a copy that isn’t production', function () {
    $this->app['env'] = 'local';
    block('185.220.101.4');

    $this->artisan('porro:cloudflare', ['action' => 'sync'])->expectsOutputToContain('Only production syncs')->assertSuccessful();

    expect($this->cloudflare->calls)->toBe(0);
});

it('refuses to sync when the site has moved off the connected zone', function () {
    $this->artisan('porro:cloudflare', ['action' => 'connect'])->assertSuccessful();
    config(['app.url' => 'https://staging.other.example']);
    block('185.220.101.4');

    expect(app(CloudflareMode::class)->sync())->toBeNull()
        ->and(app(CloudflareMode::class)->status()['error'])->toContain('connected to dogearbooks.example')
        ->and($this->cloudflare->ips())->toBe([]);
});

it('syncs after a change, once the response has gone', function () {
    $this->artisan('porro:cloudflare', ['action' => 'connect'])->assertSuccessful();
    $activity = app(Activity::class);
    $activity->blocklist()->block('185.220.101.4', 'Scanner');

    // Every panel calls changed() after a change: the sync waits for the
    // app to terminate, after the response has been sent.
    $activity->changed();
    $activity->changed();
    expect($this->cloudflare->ips())->toBe([]);

    $calls = $this->cloudflare->calls;
    $this->app->terminate();

    expect($this->cloudflare->ips())->toBe(['185.220.101.4'])
        // Queued once, however many changes there were.
        ->and($this->cloudflare->calls - $calls)->toBeLessThan(6);
});

it('drops expired blocks from Cloudflare on the scheduled task', function () {
    $this->artisan('porro:cloudflare', ['action' => 'connect'])->assertSuccessful();
    block('185.220.101.4');
    block('45.148.10.92');
    app(CloudflareMode::class)->sync();

    DB::table('porro_blocklist')->where('cidr', '45.148.10.92')->update(['expires_at' => time() - 1]);
    app(Activity::class)->maintain();

    expect($this->cloudflare->ips())->toBe(['185.220.101.4']);
});

it('disconnects', function () {
    block('185.220.101.4');
    $this->artisan('porro:cloudflare', ['action' => 'connect'])->assertSuccessful();

    $this->artisan('porro:cloudflare', ['action' => 'disconnect'])->expectsOutputToContain('Disconnected')->assertSuccessful();

    expect($this->cloudflare->ips())->toBe([])
        ->and(app(CloudflareMode::class)->status()['connected'])->toBeFalse();
});

it('shows Cloudflare on the Blocking page', function () {
    Gate::define('viewPorro', fn ($user) => true);
    $this->actingAs((new User)->forceFill(['id' => 1, 'name' => 'Ops']));

    $this->get('/porro/blocking')->assertSee('Cloudflare WAF')->assertSee('token set, not connected')->assertSee('Finish setting up');

    $this->artisan('porro:cloudflare', ['action' => 'connect'])->assertSuccessful();

    $this->get('/porro/blocking')->assertSee('zone dogearbooks.example')->assertSee('synced');
});

it('tags this site’s items with an id that stays put and differs between apps', function () {
    $first = app(CloudflareMode::class)->siteId();

    expect(app(CloudflareMode::class)->siteId())->toBe($first);

    config(['app.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);

    expect(app(CloudflareMode::class)->siteId())->not->toBe($first)->toMatch('/^[0-9a-f]{12}$/');
});

describe('the setup page', function () {
    beforeEach(function () {
        Gate::define('viewPorro', fn ($user = null) => true);
        $this->user = new User;
        $this->user->id = 1;
    });

    it('walks through the token, the environment and connecting', function () {
        config(['porro.blocklist.cloudflare_token' => null]);

        $this->actingAs($this->user)->get('/porro/blocking/cloudflare')
            ->assertOk()
            ->assertSee('1 · Create a Cloudflare API token')
            ->assertSee('https://dash.cloudflare.com/profile/api-tokens', false)
            ->assertSee('Account Rule Lists')->assertSee('Zone WAF Rules')->assertSee('Entire Account')
            ->assertSee('Zone WAF')
            ->assertSee('PORRO_CLOUDFLARE_TOKEN=', false)
            ->assertSee('Connect to Cloudflare')
            ->assertSee('www.dogearbooks.example');
    });

    it('connects and syncs from the page, no terminal needed', function () {
        block('203.0.113.9');

        $this->actingAs($this->user)->from('/porro/blocking/cloudflare')
            ->withSession(['_token' => 't'])->post('/porro/blocking/cloudflare/connect', ['_token' => 't'])
            ->assertRedirect('/porro/blocking/cloudflare')
            ->assertSessionHas('success', 'Connected to dogearbooks.example and synced the blocklist.');

        expect($this->cloudflare->ips())->toBe(['203.0.113.9'])
            ->and($this->cloudflare->rules)->toHaveCount(1);

        $this->actingAs($this->user)->get('/porro/blocking/cloudflare')->assertSee('Connected to dogearbooks.example');
    });

    it('says what Cloudflare said when connecting fails', function () {
        config(['app.url' => 'https://not-on-this-account.example']);

        $this->actingAs($this->user)->from('/porro/blocking/cloudflare')
            ->withSession(['_token' => 't'])->post('/porro/blocking/cloudflare/connect', ['_token' => 't'])
            ->assertSessionHas('error');

        expect($this->cloudflare->rules)->toBe([]);
    });

    it('only lets people who can manage the blocklist connect', function () {
        Gate::define('managePorroBlocklist', fn () => false);

        $this->actingAs($this->user)->withSession(['_token' => 't'])->post('/porro/blocking/cloudflare/connect', ['_token' => 't'])->assertForbidden();

        expect($this->cloudflare->rules)->toBe([]);
    });

    it('links the Blocking page to the setup', function () {
        config(['porro.blocklist.cloudflare_token' => null]);

        $this->actingAs($this->user)->get('/porro/blocking')
            ->assertSee('Set up Cloudflare')
            ->assertSee('/porro/blocking/cloudflare', false);
    });
});
