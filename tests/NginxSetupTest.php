<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
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

it('shows the two steps with this site’s real paths', function () {
    $this->get('/porro/blocking/nginx')
        ->assertOk()
        ->assertSee('Set up nginx')
        ->assertSee("include {$this->file};")
        ->assertSee("f={$this->file}; s={$this->dir}/stamp;", false)
        ->assertSee('No server has reported yet');
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

    $this->get('/porro/blocking/nginx')
        ->assertSee('This site is behind Cloudflare')
        ->assertSee('set_real_ip_from 173.245.48.0/20;')
        ->assertSee('Real IP lines missing');
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

    $this->get('/porro/blocking/nginx')->assertSee('nginx mode is off')->assertSee('PORRO_BLOCK_NGINX=true');
});
