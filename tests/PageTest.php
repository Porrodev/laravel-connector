<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Porro\Core\Activity\Entry;
use Porro\Core\Activity\Recorder;
use Porro\Laravel\Activity\Activity;
use Porro\Laravel\PorroServiceProvider;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake(['*' => Http::response([], 200)]);
});

function opsUser(string $email = 'ops@example.com'): User
{
    return (new User)->forceFill(['id' => 1, 'name' => 'Ops', 'email' => $email]);
}

function allowOps(): void
{
    Gate::define('viewPorro', fn (User $user) => $user->email === 'ops@example.com');
}

describe('who can open it', function () {
    it('is open in the local environment, like Horizon', function () {
        $this->app['env'] = 'local';

        $this->get('/porro')->assertOk()->assertSee('Site health');
    });

    it('is closed everywhere else when no viewPorro gate is defined', function () {
        $this->app['env'] = 'production';

        $this->get('/porro')->assertForbidden();
        $this->actingAs(opsUser())->get('/porro')->assertForbidden();
        $this->actingAs(opsUser())->get('/porro/blocking')->assertForbidden();
        // CSRF may answer first (419); either way nothing changes.
        expect($this->actingAs(opsUser())->post('/porro/blocking/block', ['ip' => '185.220.101.4'])->status())->toBeIn([403, 419])
            ->and(DB::table('porro_blocklist')->count())->toBe(0);
    });

    it('lets in only who the gate allows, never guests', function () {
        $this->app['env'] = 'production';
        allowOps();

        $this->get('/porro')->assertForbidden();
        $this->actingAs(opsUser('someone@example.com'))->get('/porro')->assertForbidden();
        $this->actingAs(opsUser())->get('/porro')->assertOk();
    });

    it('needs managePorroBlocklist to change the lists, when it’s defined', function () {
        allowOps();
        Gate::define('managePorroBlocklist', fn (User $user) => false);

        $this->actingAs(opsUser())->get('/porro/blocking')->assertOk()->assertDontSee('Wide range is OK');
        $this->actingAs(opsUser())->post('/porro/blocking/block', ['ip' => '185.220.101.4'])->assertForbidden();
    });

    it('steps aside for the Statamic and Filament connectors unless asked', function () {
        expect(PorroServiceProvider::pageEnabled())->toBeTrue();

        config(['porro.page.enabled' => 'false']);
        expect(PorroServiceProvider::pageEnabled())->toBeFalse();
    });

    it('lives at the configured path', function () {
        expect(route('porro.health', absolute: false))->toBe('/porro')
            ->and(route('porro.blocking', absolute: false))->toBe('/porro/blocking');
    });
});

describe('pages', function () {
    beforeEach(function () {
        allowOps();
        $this->actingAs(opsUser());
    });

    it('shows site health with the checks run on the site', function () {
        $this->get('/porro')
            ->assertOk()
            ->assertSee('Checked on this site')
            ->assertSee('Watch this site from outside too')
            ->assertSee('Back to app');
    });

    it('lists flagged IPs on Activity with country and block buttons', function () {
        $recorder = app(Activity::class)->recorder();

        foreach (['/.env', '/.git/config', '/<script>alert(1)</script>'] as $path) {
            $recorder->record('185.220.101.4', Recorder::PROBE, 'GET', $path, 404, country: 'DE');
        }

        $this->get('/porro/activity')
            ->assertOk()
            ->assertSee('185.220.101.4')
            ->assertSee('🇩🇪 DE')
            ->assertSee('probe 3')
            ->assertSee('Block 7 days')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false);
    });

    it('blocks, and the IP is turned away', function () {
        $this->from('/porro/blocking')
            ->post('/porro/blocking/block', ['ip' => '185.220.101.4', 'reason' => 'Scanner', 'days' => 7])
            ->assertRedirect('/porro/blocking')
            ->assertSessionHas('success', 'Blocked 185.220.101.4.');

        expect(DB::table('porro_blocklist')->value('added_by'))->toBe('Ops');

        $this->get('/porro/blocking')->assertSee('Scanner');
        $this->withServerVariables(['REMOTE_ADDR' => '185.220.101.4'])->get('/porro')->assertForbidden();
    });

    it('shows why a block was refused, in the right form', function () {
        $this->from('/porro/blocking')->post('/porro/blocking/block', ['form' => 'block', 'ip' => '159.203.150.232']);

        $html = $this->get('/porro/blocking')->assertSee('is a Laravel Forge address')->getContent();

        expect(substr_count($html, 'value="159.203.150.232"'))->toBe(1);
    });

    it('safelists, asking before a wide range, and unblocks', function () {
        DB::table('porro_blocklist')->insert((new Entry('185.220.101.4', 'x', Entry::MANUAL, time()))->toArray());

        $this->post('/porro/blocking/safelist', ['ip' => '5.0.0.0/8', 'label' => 'ISP'])->assertSessionHas('error', fn ($m) => str_contains($m, 'Confirm'));
        $this->post('/porro/blocking/safelist', ['ip' => '5.0.0.0/8', 'label' => 'ISP', 'confirm_wide' => 1])->assertSessionHas('success');
        $this->post('/porro/blocking/unblock', ['ip' => '185.220.101.4'])->assertSessionHas('success');

        expect(DB::table('porro_safelist')->pluck('cidr')->all())->toBe(['5.0.0.0/8'])
            ->and(DB::table('porro_blocklist')->count())->toBe(0);
    });

    it('opens each always-protected list to show its addresses and why', function () {
        $this->get('/porro/blocking')
            ->assertSeeInOrder(['Always protected', 'Cloudflare', 'Laravel Forge', 'Forge health checks', 'Search engine crawlers', 'Private and reserved'])
            ->assertSee('<details class="protected">', false)
            ->assertSee('drop the site from search')
            ->assertSee('developers.google.com/static/crawling/ipranges/common-crawlers.json')
            ->assertSee('173.245.48.0/20')
            ->assertSee('PORRO_PROTECT_CRAWLERS=false');
    });

    it('lets a site switch off Forge and crawler protection, but not Cloudflare', function () {
        config(['porro.blocklist.protect' => ['forge' => false, 'forge_health' => false, 'crawlers' => false, 'cloudflare' => false]]);
        $this->app->forgetInstance(Activity::class);

        $this->get('/porro/blocking')->assertSee('Switched off')->assertSee('Switched off with PORRO_PROTECT_FORGE=false');
        $this->from('/porro/blocking')->post('/porro/blocking/block', ['ip' => '159.203.150.232'])->assertSessionHas('success');
        $this->from('/porro/blocking')->post('/porro/blocking/block', ['ip' => '104.16.1.1'])->assertSessionHas('error');
    });

    it('prefills the safelist from a flagged IP', function () {
        $this->get('/porro/blocking?safelist=81.2.69.142')->assertSee('value="81.2.69.142"', false);
    });

    it('checks again on request', function () {
        $this->post('/porro/refresh')->assertRedirect('/porro')->assertSessionHas('success', 'Checked again.');
    });
});

describe('the full lists', function () {
    beforeEach(function () {
        allowOps();
        $this->actingAs(opsUser());

        $rows = [];
        for ($i = 1; $i <= 60; $i++) {
            $rows[] = (new Entry("203.0.113.{$i}", $i % 2 ? 'Probing for .env' : 'Failed logins', $i % 3 ? Entry::SUGGESTED : Entry::MANUAL, 1000 + $i, $i <= 5 ? time() + 3 * 86400 : null, $i === 7 ? 'Daniel' : 'Ops'))->toArray();
        }
        $rows[] = (new Entry('198.51.100.0/24', 'Scanner network', Entry::MANUAL, 5000))->toArray();
        DB::table('porro_blocklist')->insert($rows);
    });

    it('shows the newest ten on Blocking, and links to the rest', function () {
        $html = $this->get('/porro/blocking')->assertSee('Show all 61, with search')->assertSee(route('porro.list', 'blocklist'))->getContent();

        expect(substr_count($html, 'name="ip" value="203.0.113.'))->toBe(9)
            ->and($html)->toContain('198.51.100.0/24')
            ->and($html)->not->toContain('value="203.0.113.1"');
    });

    it('pages through the whole list, newest first, fifty at a time', function () {
        $first = $this->get('/porro/blocking/blocklist')->assertOk()->assertSee('61 entries')->assertSee('Page 1 of 2')->assertSee('Older')->getContent();

        expect(substr_count($first, 'name="ip" value='))->toBe(50)
            ->and(strpos($first, '198.51.100.0/24'))->toBeLessThan(strpos($first, '203.0.113.60'));

        $this->get('/porro/blocking/blocklist?page=2')->assertSee('Page 2 of 2')->assertSee('value="203.0.113.1"', false)->assertSee('Newer');
    });

    it('finds the entries covering an IP, or matching a reason or who added them', function () {
        $this->get('/porro/blocking/blocklist?q=198.51.100.77')->assertSee('198.51.100.0/24')->assertDontSee('203.0.113.');
        expect(substr_count($this->get('/porro/blocking/blocklist?q=failed')->getContent(), 'name="ip" value='))->toBe(30);
        $this->get('/porro/blocking/blocklist?q=daniel')->assertSee('value="203.0.113.7"', false)->assertDontSee('value="203.0.113.8"', false);
        $this->get('/porro/blocking/blocklist?q=nothing-like-this')->assertSee('Nothing matches.');
    });

    it('filters by source and by what’s expiring soon, with counts', function () {
        $this->get('/porro/blocking/blocklist?filter=expiring')->assertSee('Expiring within 7 days 5')->assertSee('value="203.0.113.5"', false)->assertDontSee('value="203.0.113.6"', false);
        expect(substr_count($this->get('/porro/blocking/blocklist?filter=manual')->getContent(), 'name="ip" value='))->toBe(21);
        $this->get('/porro/blocking/blocklist?filter=nonsense')->assertOk()->assertSee('Page 1 of 2');
    });

    it('ignores a query string that isn’t plain values', function () {
        $this->get('/porro/blocking/blocklist?q[]=x&filter[]=manual&page[]=2')->assertOk()->assertSee('Page 1 of 2');
    });

    it('has a safelist page too, and nothing else', function () {
        $this->get('/porro/blocking/safelist')->assertOk()->assertSee('Nothing is safelisted.');
        $this->get('/porro/blocking/other')->assertNotFound();
    });
});
