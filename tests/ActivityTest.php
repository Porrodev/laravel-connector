<?php

use Illuminate\Auth\Events\Failed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Porro\Core\Activity\Entry;
use Porro\Laravel\Activity\Activity;
use Porro\Laravel\Activity\CloudflareMode;
use Porro\Laravel\Activity\WatchRequests;
use Porro\Laravel\SiteHealth;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app['env'] = 'production';
    Http::fake([
        'endoflife.date/*' => Http::response([['cycle' => '8.5', 'eol' => '2029-12-31']]),
        'packagist.org/*' => Http::response(['advisories' => []]),
        'repo.packagist.org/*' => Http::response(['packages' => []]),
        '*' => Http::response('', 500),
    ]);
    Route::get('/', fn () => 'home');
    Route::post('/contact', fn () => 'thanks');
    Route::post('/livewire/update', fn () => 'ok');
    Route::post('/login', function () {
        event(new Failed('web', null, ['email' => request('email'), 'password' => 'secret']));

        return response('no', 422);
    });
});

function from(string $ip, array $headers = []): array
{
    return ['REMOTE_ADDR' => $ip] + collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all();
}

function flagged(): array
{
    return app(Activity::class)->summary()->flagged;
}

describe('recording', function () {
    it('records scanner probes and flags the IP, even when no route matches', function () {
        foreach (['/.env', '/.git/config', '/wp-login.php'] as $path) {
            $this->withServerVariables(from('185.220.101.4'))->get($path)->assertNotFound();
        }

        expect(flagged())->toHaveCount(1)
            ->and(flagged()[0]->ip)->toBe('185.220.101.4')
            ->and(flagged()[0]->counts['probe'])->toBe(3)
            ->and(flagged()[0]->lastPath)->toBe('/wp-login.php');
    });

    it('takes the visitor and their country from Cloudflare', function () {
        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables(from('104.16.1.1', ['CF-Connecting-IP' => '185.220.101.4', 'CF-IPCountry' => 'DE']))->get('/.env');
        }

        expect(flagged()[0]->ip)->toBe('185.220.101.4')
            ->and(flagged()[0]->country)->toBe('DE');
    });

    it('records nothing outside production unless asked', function () {
        $this->app['env'] = 'local';

        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables(from('185.220.101.4'))->get('/.env');
        }

        expect(app(Activity::class)->summary()->totals['probe'])->toBe(0);

        config(['porro.activity.enabled' => 'true']);

        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables(from('185.220.101.4'))->get('/.env');
        }

        expect(flagged())->toHaveCount(1);
    });

    it('counts form posts but not app chatter like Livewire', function () {
        config(['porro.activity.thresholds' => ['post_flood' => 3]]);

        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables(from('45.148.10.92'))->post('/contact');
            $this->withServerVariables(from('103.75.190.11'))->post('/livewire/update');
        }

        expect(array_map(fn ($ip) => $ip->ip, flagged()))->toBe(['45.148.10.92']);
    });

    it('counts failed logins and the accounts they tried', function () {
        foreach (range(1, 5) as $i) {
            $this->withServerVariables(from('194.26.29.113'))->post('/login', ['email' => "user{$i}@example.test"]);
        }

        expect(flagged()[0]->ip)->toBe('194.26.29.113')
            ->and(flagged()[0]->users)->toBe(5)
            ->and(flagged()[0]->recent[0][0])->toBe('POST');
    });

    it('raises a critical issue when /.env answers 200', function () {
        Route::get('/.env', fn () => "APP_KEY=base64:oops\n");

        $this->withServerVariables(from('185.220.101.4'))->get('/.env')->assertOk();

        $issues = collect(app(SiteHealth::class)->get(fresh: true)['issues']);

        expect($issues->firstWhere('kind', 'exposed_file'))->toMatchArray(['severity' => 'critical', 'title' => '/.env is publicly readable']);
    });
});

describe('blocking', function () {
    it('turns blocked IPs away before the app runs', function () {
        $this->artisan('porro:block', ['ip' => '185.220.101.4', '--reason' => 'Scanner'])->assertSuccessful();

        $this->withServerVariables(from('185.220.101.4'))->get('/')
            ->assertForbidden()
            ->assertHeader('X-Porro-Blocked', '1');

        $this->withServerVariables(from('45.148.10.92'))->get('/')->assertOk()->assertSee('home');
    });

    it('blocks the real visitor behind Cloudflare, never Cloudflare itself', function () {
        $this->artisan('porro:block', ['ip' => '185.220.101.0/24'])->assertSuccessful();

        $this->withServerVariables(from('104.16.1.1', ['CF-Connecting-IP' => '185.220.101.4']))->get('/')->assertForbidden();
        $this->withServerVariables(from('104.16.1.1', ['CF-Connecting-IP' => '45.148.10.92']))->get('/')->assertOk();
    });

    it('lets safelisted addresses through even if a block slipped in', function () {
        config(['porro.blocklist.safelist' => ['81.2.69.142']]);
        DB::table('porro_blocklist')->insert((new Entry('81.2.69.142', 'stale', Entry::MANUAL, time()))->toArray());

        $this->withServerVariables(from('81.2.69.142'))->get('/')->assertOk();
    });

    it('can be turned off', function () {
        $this->artisan('porro:block', ['ip' => '185.220.101.4'])->assertSuccessful();
        config(['porro.blocklist.app' => false]);

        $this->withServerVariables(from('185.220.101.4'))->get('/')->assertOk();
    });

    it('refuses blocks that would break the site', function () {
        $this->artisan('porro:block', ['ip' => '159.203.150.232'])
            ->expectsOutputToContain('Laravel Forge address')
            ->assertFailed();

        expect(DB::table('porro_blocklist')->count())->toBe(0);
    });

    it('unblocks', function () {
        $this->artisan('porro:block', ['ip' => '185.220.101.4'])->assertSuccessful();
        $this->artisan('porro:block', ['ip' => '185.220.101.4', '--remove' => true])->assertSuccessful();

        $this->withServerVariables(from('185.220.101.4'))->get('/')->assertOk();
    });

    it('stops enforcing an expired block before it’s pruned', function () {
        DB::table('porro_blocklist')->insert((new Entry('185.220.101.4', 'old', Entry::SUGGESTED, time() - 86400 * 8, time() - 60))->toArray());

        $this->withServerVariables(from('185.220.101.4'))->get('/')->assertOk();
    });

    it('still records a scanner that sends a bad or untrusted Host', function () {
        $request = Request::create('/.env', 'GET', server: ['REMOTE_ADDR' => '185.220.101.4', 'HTTP_HOST' => 'a!b']);

        // The old code read the host unguarded, and this threw.
        expect(fn () => $request->getHost())->toThrow(Exception::class);

        app(WatchRequests::class)->terminate($request, new Response('', 404));

        expect(app(Activity::class)->summary()->totals['probe'])->toBe(1)
            ->and(app(Activity::class)->recorder()->history('185.220.101.4')->hosts)->toBe([]);
    });

    it('counts a POST flood even when it claims to be a PUT', function () {
        foreach (range(1, 3) as $i) {
            $this->withServerVariables(from('185.220.101.4'))->post('/contact', ['_method' => 'PUT']);
        }

        expect(app(Activity::class)->summary()->totals['post_flood'])->toBe(3);
    });

    it('counts every request from a flagged IP, and those a block turns away', function () {
        foreach (['/containers/json', '/images/json', '/.env'] as $path) {
            $this->withServerVariables(from('185.177.72.69'))->get($path);
        }
        app(Activity::class)->changed();

        $this->withServerVariables(from('185.177.72.69'))->get('/')->assertOk();
        $this->withServerVariables(from('81.2.69.160'))->get('/')->assertOk();

        app(Activity::class)->blocklist()->block('185.177.72.69', 'Scanner', Entry::MANUAL, null, 'tests');
        app(Activity::class)->changed();
        $this->withServerVariables(from('185.177.72.69'))->get('/info')->assertForbidden();

        $ip = app(Activity::class)->recorder()->history('185.177.72.69');

        expect($ip->requests)->toBe(5)
            ->and($ip->turnedAway)->toBe(1)
            ->and($ip->categories)->toBe(['infrastructure' => 2, 'secrets' => 1])
            ->and($ip->paths['/'])->toBe([1, 200])
            ->and($ip->direct)->toBe(5)
            ->and(app(Activity::class)->recorder()->history('81.2.69.160'))->toBeNull();
    });

    it('fails open when the tables don’t exist yet', function () {
        DB::statement('DROP TABLE porro_blocklist');
        app(Activity::class)->changed();

        $this->withServerVariables(from('185.220.101.4'))->get('/')->assertOk();
    });
});

describe('surviving deploys', function () {
    it('keeps activity and the Cloudflare connection through cache:clear', function () {
        foreach (['/.env', '/.git/config', '/wp-login.php'] as $path) {
            $this->withServerVariables(from('185.220.101.4'))->get($path);
        }
        Activity::store()->forever(CloudflareMode::STATE, ['connection' => ['zone_name' => 'example.com']]);

        $this->artisan('cache:clear')->assertSuccessful();

        expect(flagged())->toHaveCount(1)
            ->and(flagged()[0]->counts['probe'])->toBe(3)
            ->and(Activity::store()->get(CloudflareMode::STATE)['connection']['zone_name'])->toBe('example.com')
            ->and(DB::table(Activity::TABLE)->count())->toBeGreaterThan(0);
    });

    it('records in the app’s cache until its own table exists', function () {
        Schema::drop(Activity::TABLE);
        Cache::forget('porro:cache:ready');

        $this->withServerVariables(from('185.220.101.4'))->get('/.env');

        expect(flagged())->toHaveCount(0)
            ->and(app(Activity::class)->summary()->totals['probe'])->toBe(1);
    });

    it('uses another store when PORRO_ACTIVITY_STORE names one', function () {
        config(['porro.activity.store' => 'array']);

        $this->withServerVariables(from('185.220.101.4'))->get('/.env');

        expect(DB::table(Activity::TABLE)->count())->toBe(0)
            ->and(app(Activity::class)->summary()->totals['probe'])->toBe(1);
    });

    it('clears expired rows and carries older state across', function () {
        DB::table(Activity::TABLE)->insert(['key' => 'porro:activity:old', 'value' => serialize(1), 'expiration' => time() - 10]);
        Cache::forever(CloudflareMode::STATE, ['connection' => ['zone_name' => 'example.com']]);

        app(Activity::class)->maintain();

        expect(DB::table(Activity::TABLE)->where('key', 'porro:activity:old')->exists())->toBeFalse()
            ->and(Activity::store()->get(CloudflareMode::STATE)['connection']['zone_name'])->toBe('example.com')
            ->and(Cache::get(CloudflareMode::STATE))->toBeNull();
    });
});

describe('sync', function () {
    it('prunes expired blocks', function () {
        DB::table('porro_blocklist')->insert((new Entry('185.220.101.4', 'old', Entry::SUGGESTED, time() - 86400 * 8, time() - 60))->toArray());

        $this->artisan('porro:blocklist-sync')
            ->expectsOutputToContain('185.220.101.4')
            ->assertSuccessful();

        expect(DB::table('porro_blocklist')->count())->toBe(0);
    });

    it('writes the nginx file in production only', function () {
        $path = sys_get_temp_dir().'/porro-'.bin2hex(random_bytes(4)).'/nginx-blocklist.conf';
        config(['porro.blocklist.nginx' => true, 'porro.blocklist.nginx_path' => $path]);
        $this->artisan('porro:block', ['ip' => '185.220.101.4'])->assertSuccessful();

        $this->app['env'] = 'local';
        $this->artisan('porro:blocklist-sync')->expectsOutputToContain('outside production')->assertSuccessful();
        expect(file_exists($path))->toBeFalse();

        $this->app['env'] = 'production';
        $this->artisan('porro:blocklist-sync')->assertSuccessful();
        expect(file_get_contents($path))->toContain('deny 185.220.101.4;');
    });

    it('adds a scheduled task only in nginx mode, so other hosts can sleep', function () {
        $sync = fn () => collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'porro:blocklist-sync'));

        expect($sync())->toHaveCount(0);
    });

    it('runs every minute in nginx mode', function () {
        config(['porro.blocklist.nginx' => true]);
        app()->forgetInstance(Schedule::class);

        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'porro:blocklist-sync'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('* * * * *');
    });
});

describe('site health', function () {
    it('adds activity to the panel', function () {
        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables(from('104.16.1.1', ['CF-Connecting-IP' => '185.220.101.4', 'CF-IPCountry' => 'DE']))->get('/.git/config');
        }

        $this->artisan('porro:block', ['ip' => '45.148.10.92'])->assertSuccessful();

        $health = app(SiteHealth::class)->get(fresh: true);

        expect($health['activity']['totals']['probe'])->toBe(3)
            ->and($health['activity']['flagged'][0])->toMatchArray(['ip' => '185.220.101.4', 'country' => 'DE'])
            ->and($health['activity']['blocked'])->toBe(1)
            ->and($health['activity']['recording'])->toBeTrue()
            ->and(collect($health['issues'])->firstWhere('kind', 'suspicious_activity')['title'])->toBe('1 IP probing for secrets and admin tools')
            ->and($health['checked'])->toContain('suspicious activity');
    });

    it('names an account tried from many IPs when it’s one of the app’s users', function () {
        config(['auth.providers.users.model' => PorroTestUser::class]);
        DB::statement('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');
        DB::table('users')->insert(['email' => 'admin@dogearbooks.example']);

        foreach (['45.148.10.92', '194.26.29.113', '103.75.190.11', '185.220.101.4', '45.148.10.93'] as $ip) {
            $this->withServerVariables(from($ip))->post('/login', ['email' => 'Admin@DogEarBooks.example']);
        }

        $titles = collect(app(SiteHealth::class)->get(fresh: true)['issues'])->pluck('title');

        expect($titles)->toContain('admin@dogearbooks.example targeted from 5 IPs');
    });
});

class PorroTestUser extends Model
{
    protected $table = 'users';
}
