<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Porro\Laravel\Activity\Activity;
use Porro\Laravel\Activity\WatchRequests;
use Porro\Laravel\PorroServiceProvider;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app['env'] = 'production';
    // Symfony keeps trusted proxies in a static: one test's never leaks into the next.
    Request::setTrustedProxies([], -1);
    $this->beforeApplicationDestroyed(fn () => Request::setTrustedProxies([], -1));
    Route::get('/', fn () => 'home');
});

function via(string $remote, array $headers = []): array
{
    return ['REMOTE_ADDR' => $remote] + collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all();
}

function scanned(): array
{
    return array_map(fn ($ip) => $ip->ip, app(Activity::class)->summary()->flagged);
}

class AppTrustProxies extends TrustProxies {}

describe('the stack', function () {
    it('runs straight after the app’s TrustProxies', function () {
        $stack = app(Kernel::class)->getGlobalMiddleware();

        expect($stack[array_search(TrustProxies::class, $stack) + 1])->toBe(WatchRequests::class)
            ->and(array_count_values($stack)[WatchRequests::class])->toBe(1);
    });

    it('finds an app’s own TrustProxies, and goes first when there is none', function () {
        expect(PorroServiceProvider::afterTrustProxies(['A', AppTrustProxies::class, 'B']))->toBe(['A', AppTrustProxies::class, WatchRequests::class, 'B'])
            ->and(PorroServiceProvider::afterTrustProxies(['A', 'B']))->toBe([WatchRequests::class, 'A', 'B'])
            ->and(PorroServiceProvider::afterTrustProxies([WatchRequests::class, TrustProxies::class]))->toBe([TrustProxies::class, WatchRequests::class]);
    });
});

describe('the visitor', function () {
    it('is the connecting address on a direct request', function () {
        $this->artisan('porro:block', ['ip' => '185.220.101.4'])->assertSuccessful();

        $this->withServerVariables(via('185.220.101.4'))->get('/')->assertForbidden();
        $this->withServerVariables(via('45.148.10.92'))->get('/')->assertOk();
    });

    it('is Cloudflare’s visitor, straight from Cloudflare', function () {
        $this->artisan('porro:block', ['ip' => '185.220.101.4'])->assertSuccessful();

        $this->withServerVariables(via('104.16.1.1', ['CF-Connecting-IP' => '185.220.101.4']))->get('/')->assertForbidden();
        $this->withServerVariables(via('104.16.1.1', ['CF-Connecting-IP' => '45.148.10.92']))->get('/')->assertOk();
    });

    it('is the forwarded address behind a load balancer the app trusts', function () {
        TrustProxies::at('10.0.0.0/8');
        $this->artisan('porro:block', ['ip' => '185.220.101.4'])->assertSuccessful();

        $this->withServerVariables(via('10.0.0.5', ['X-Forwarded-For' => '185.220.101.4']))->get('/')->assertForbidden();
        $this->withServerVariables(via('10.0.0.5', ['X-Forwarded-For' => '45.148.10.92']))->get('/')->assertOk();

        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables(via('10.0.0.5', ['X-Forwarded-For' => '45.148.10.92']))->get('/.env');
        }

        // Not "can't see visitor IPs": the load balancer is out of the way.
        expect(scanned())->toBe(['45.148.10.92'])
            ->and(app(Activity::class)->summary()->proxied)->toBe(0);
    });

    it('is Cloudflare’s visitor through Cloudflare and a trusted load balancer', function () {
        TrustProxies::at('10.0.0.0/8');
        $this->artisan('porro:block', ['ip' => '185.220.101.4'])->assertSuccessful();

        $cloudflare = fn (string $visitor) => via('10.0.0.5', ['X-Forwarded-For' => "{$visitor}, 104.16.1.1", 'CF-Connecting-IP' => $visitor]);

        $this->withServerVariables($cloudflare('185.220.101.4'))->get('/')->assertForbidden();
        $this->withServerVariables($cloudflare('45.148.10.92'))->get('/')->assertOk();

        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables($cloudflare('45.148.10.92'))->get('/.env');
        }

        $history = app(Activity::class)->history('45.148.10.92');

        expect(scanned())->toBe(['45.148.10.92'])
            ->and($history->viaCloudflare)->toBe(3)
            ->and($history->direct)->toBe(0)
            ->and(app(Activity::class)->summary()->proxied)->toBe(0);
    });

    it('never believes X-Forwarded-For from an address the app doesn’t trust', function () {
        TrustProxies::at('10.0.0.0/8');
        $this->artisan('porro:block', ['ip' => '185.220.101.4'])->assertSuccessful();

        // A blocked IP can't hide behind a forged header, nor frame another.
        $this->withServerVariables(via('185.220.101.4', ['X-Forwarded-For' => '45.148.10.92']))->get('/')->assertForbidden();
        $this->withServerVariables(via('45.148.10.92', ['X-Forwarded-For' => '185.220.101.4']))->get('/')->assertOk();

        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables(via('81.2.69.142', ['X-Forwarded-For' => '45.148.10.92']))->get('/.env');
        }

        expect(scanned())->toBe(['81.2.69.142']);
    });

    it('is the connecting address when the app trusts no proxy', function () {
        $this->artisan('porro:block', ['ip' => '185.220.101.4'])->assertSuccessful();

        $this->withServerVariables(via('10.0.0.5', ['X-Forwarded-For' => '185.220.101.4']))->get('/')->assertOk();
    });
});
