<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Porro\Core\Health\Issue;
use Porro\Core\Health\SiteHealth as Checks;
use Porro\Laravel\Heartbeat;
use Porro\Laravel\SiteHealth;

function fakeHealthApis(array $porro = []): void
{
    Http::fake([
        'endoflife.date/*' => Http::response([['cycle' => '13', 'eol' => '2028-03-01'], ['cycle' => '12', 'eol' => '2027-02-24'], ['cycle' => '8.5', 'eol' => '2029-12-31'], ['cycle' => '8.4', 'eol' => '2028-12-31'], ['cycle' => '8.3', 'eol' => '2027-12-31'], ['cycle' => '8.2', 'eol' => '2026-12-31']]),
        'packagist.org/api/security-advisories/' => Http::response(['advisories' => []]),
        'repo.packagist.org/*' => Http::response(['packages' => []]),
        'porro.test/api/v1/findings' => Http::response($porro + ['ok' => true, 'site' => ['url' => 'https://porro.test/acme/sites/example'], 'findings' => []]),
    ]);
}

it('checks the site without a Porro account', function () {
    fakeHealthApis();
    config(['porro.key' => null, 'app.debug' => true, 'app.env' => 'production']);
    $this->app['env'] = 'production';

    $health = app(SiteHealth::class)->get();

    expect($health['connected'])->toBeFalse()
        ->and($health['porro'])->toBeNull()
        ->and(array_column($health['issues'], 'title'))->toContain('Debug mode is on in production', 'Scheduler isn’t running')
        ->and($health['commands'])->toBe(['connect' => 'php artisan porro:connect <site key>', 'cron' => 'php artisan porro:cron']);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'porro.test'));
});

it('sees the scheduler from its heartbeat', function () {
    fakeHealthApis();
    Heartbeat::beat();

    expect(array_column(app(SiteHealth::class)->get()['issues'], 'kind'))->not->toContain('scheduler_stale');
});

it('adds the site’s Porro findings once connected, signed with the site key', function () {
    fakeHealthApis(['findings' => [['kind' => 'ssl_expiring', 'rank' => 3, 'title' => 'SSL certificate expires in 9 days', 'scope' => 'site', 'url' => 'https://porro.test/acme/sites/example']]]);
    $key = siteKey();
    config(['porro.key' => $key['token']]);

    $health = app(SiteHealth::class)->get();

    expect($health['connected'])->toBeTrue()
        ->and($health['porro']['ok'])->toBeTrue()
        ->and($health['porro']['site_url'])->toBe('https://porro.test/acme/sites/example')
        ->and($health['porro']['findings'][0])->toMatchArray(['title' => 'SSL certificate expires in 9 days', 'severity' => Issue::WARNING, 'source' => 'porro']);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://porro.test/api/v1/findings'
        && $request->method() === 'GET'
        && signatureIsValid(array_map(fn ($values) => $values[0], $request->headers()), '', $key['public'], 'GET', '/api/v1/findings'));
});

it('treats an oversized response as a failure without reading all of it', function () {
    Http::fake(['porro.test/api/v1/findings' => Http::response(str_repeat(' ', 6 * 1024 * 1024).'{"ok":true,"findings":[]}')]);
    fakeHealthApis();
    config(['porro.key' => siteKey()['token']]);

    expect(app(SiteHealth::class)->get()['porro']['ok'])->toBeFalse();
});

it('caches the panel for five minutes unless asked to check again', function () {
    fakeHealthApis();
    config(['porro.key' => siteKey()['token']]);

    app(SiteHealth::class)->get();
    app(SiteHealth::class)->get();
    Http::assertSentCount(4);

    // Porro's findings are fetched again; the public data keeps its own cache.
    app(SiteHealth::class)->get(fresh: true);
    Http::assertSentCount(5);
});

it('hands back the last result without checking anything', function () {
    fakeHealthApis();

    expect(app(SiteHealth::class)->cached())->toBeNull();

    $health = app(SiteHealth::class)->get();
    Http::assertSentCount(3);

    expect(app(SiteHealth::class)->cached())->toBe($health);
    Http::assertSentCount(3);
});

it('lets layers add their own checks', function () {
    fakeHealthApis();
    app()->tag([ExtraCheck::class], 'porro.health');

    expect(array_column(app(SiteHealth::class)->get()['issues'], 'title'))->toContain('Something only the layer knows');
});

it('runs a heartbeat on the scheduler when the site isn’t connected', function () {
    config(['porro.key' => null]);

    $events = collect(app(Schedule::class)->events());

    expect($events->pluck('description')->all())->toContain('porro:heartbeat')
        ->and($events->filter(fn ($event) => str_contains((string) $event->command, 'porro:report')))->toBeEmpty();
});

class ExtraCheck
{
    public function check(Checks $health): void
    {
        $health->add(new Issue('cms_update', Issue::NOTICE, 'Something only the layer knows'));
    }
}
