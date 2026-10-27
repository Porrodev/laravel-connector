<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Porro\Laravel\Activity\Activity;
use Porro\Laravel\Activity\NginxServers;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/porro-nginx-'.bin2hex(random_bytes(4));
    mkdir($this->dir.'/sites-enabled', 0755, true);
    $this->file = $this->dir.'/storage/nginx-blocklist.conf';

    config([
        'porro.blocklist.nginx' => true,
        'porro.blocklist.nginx_path' => $this->file,
        'porro.blocklist.nginx_config_dirs' => [$this->dir.'/sites-enabled'],
        'porro.blocklist.nginx_stamp' => $this->dir.'/stamp',
    ]);

    $this->app['env'] = 'production';
    Gate::define('viewPorro', fn ($user) => true);
    $this->actingAs((new User)->forceFill(['id' => 1, 'name' => 'Ops']));
});

function nginxConfig(string $body): void
{
    file_put_contents(test()->dir.'/sites-enabled/example.com', "server {\n{$body}\n}\n");
}

it('walks through three steps with this site’s real paths, showing where the line goes', function () {
    config(['app.url' => 'https://dogearbooks.example']);

    $this->get('/porro/blocking/nginx')
        ->assertOk()
        ->assertSee('Set up nginx')
        ->assertSeeInOrder(['1 · Turn on nginx mode', 'Done', '2 · Add one line', '3 · Reload nginx'])
        ->assertSee("include {$this->file};")
        ->assertSee('data-porro-copy="include '.$this->file.';"', false)
        ->assertSeeInOrder(['server_name dogearbooks.example;', 'root '.public_path().';', "include {$this->file};", 'add this line', 'location / {'])
        ->assertSee("f={$this->file}; s={$this->dir}/stamp;", false)
        ->assertSee('No server has checked in yet');
});

it('counts the steps done across servers', function () {
    Cache::forever(NginxServers::KEY, [
        'web-1' => ['state' => 'loaded', 'checked_at' => time() - 30, 'reloaded' => time() - 60],
        'web-2' => ['state' => 'waiting_reload', 'checked_at' => time() - 40],
    ]);

    $this->get('/porro/blocking/nginx')->assertSeeInOrder(['2 · Add one line', 'Done on 2 of 2 servers', '3 · Reload nginx', 'Done on 1 of 2 servers']);
});

it('reports each server’s progress as the sync runs', function () {
    nginxConfig('    listen 443;');
    $this->artisan('porro:blocklist-sync')->expectsOutputToContain('Include missing')->assertSuccessful();
    $this->get('/porro/blocking/nginx')->assertSee(NginxServers::name())->assertSee('Include missing');

    nginxConfig("    include {$this->file};");
    $this->artisan('porro:blocklist-sync')->expectsOutputToContain('Waiting for reload')->assertSuccessful();

    touch($this->dir.'/stamp', time() + 5);
    $this->artisan('porro:blocklist-sync')->expectsOutputToContain('Loaded')->assertSuccessful();

    $this->get('/porro/blocking/nginx')->assertSee('Loaded')->assertSee('reloaded');
    $this->get('/porro/blocking')->assertSee('1 of 1 server loaded')->assertSee('Setup and servers');
});

it('warns when a server behind Cloudflare lacks the real IP lines', function () {
    config(['porro.blocklist.cloudflare_token' => 'token']);
    nginxConfig("    include {$this->file};");
    $this->artisan('porro:blocklist-sync')->assertSuccessful();
    touch($this->dir.'/stamp', time() + 5);
    $this->artisan('porro:blocklist-sync')->assertSuccessful();

    // On its own: the real IP lines go in the file, so nothing is missing.
    expect(file_get_contents($this->file))->toContain('real_ip_header CF-Connecting-IP;');
    $this->get('/porro/blocking/nginx')
        ->assertSee('the file also carries Cloudflare’s real IP lines')
        ->assertDontSee('Real IP lines missing');

    // Turned off: the config needs them, and the server without them says so.
    config(['porro.blocklist.nginx_real_ip' => 'false']);
    $this->artisan('porro:blocklist-sync')->assertSuccessful();
    touch($this->dir.'/stamp', time() + 10);
    $this->artisan('porro:blocklist-sync')->assertSuccessful();

    expect(file_get_contents($this->file))->not->toContain('real_ip_header');
    $this->get('/porro/blocking/nginx')
        ->assertSee('PORRO_NGINX_REAL_IP is off')
        ->assertSee('set_real_ip_from 173.245.48.0/20;')
        ->assertSee('Real IP lines missing');
});

it('puts the real IP lines in the file once the page is seen through Cloudflare', function () {
    expect(app(Activity::class)->nginxRealIp())->toBeFalse();

    $this->withServerVariables(['REMOTE_ADDR' => '173.245.48.10'])->get('/porro/blocking/nginx')->assertOk();

    expect(app(Activity::class)->nginxRealIp())->toBeTrue();
});

it('lists every server that reported, worst first, and forgets quiet ones', function () {
    Cache::forever(NginxServers::KEY, [
        'web-1' => ['state' => 'loaded', 'checked_at' => time() - 30, 'reloaded' => time() - 60],
        'web-2' => ['state' => 'waiting_reload', 'checked_at' => time() - 40],
        'old-box' => ['state' => 'loaded', 'checked_at' => time() - 2 * 86400],
    ]);

    $this->artisan('porro:blocklist-sync')->assertSuccessful();

    expect(array_keys(app(NginxServers::class)->all()))->not->toContain('old-box')
        ->and(array_column(app(NginxServers::class)->present(), 'name'))->toBe([NginxServers::name(), 'web-2', 'web-1']);
});

it('says when nginx mode is off', function () {
    config(['porro.blocklist.nginx' => false]);

    $this->get('/porro/blocking/nginx')->assertSeeInOrder(['1 · Turn on nginx mode', 'To do', 'PORRO_BLOCK_NGINX=true']);
});
