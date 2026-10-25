<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\SqlServerConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Porro\Laravel\Collectors\LaravelCollector;
use Porro\Laravel\Heartbeat;
use Porro\Laravel\Reporter;

beforeEach(function () {
    $this->app->setBasePath(__DIR__.'/fixtures/app');
});

it('reports Laravel facts without any values or secrets', function () {
    config(['app.debug' => true, 'app.key' => 'base64:SECRET', 'database.connections.testing.password' => 'hunter2']);
    Heartbeat::beat();

    $report = app(Reporter::class)->build()->toArray();

    expect($report['platform'])->toMatchArray(['name' => 'laravel', 'version' => app()->version()])
        ->and($report['health']['debug'])->toBeTrue()
        ->and($report['health']['scheduler_last_run'])->not->toBeNull()
        ->and($report['lock']['packages'])->toHaveCount(4)
        ->and(json_encode($report))->not->toContain('SECRET')->not->toContain('hunter2');
});

it('counts failed jobs from the last 24 hours', function () {
    config(['queue.failed.driver' => 'database-uuids', 'queue.failed.table' => 'failed_jobs', 'queue.failed.database' => 'testing']);
    Schema::create('failed_jobs', function ($table) {
        $table->id();
        $table->string('uuid');
        $table->timestamp('failed_at');
    });
    DB::table('failed_jobs')->insert([['uuid' => 'a', 'failed_at' => now()->subHour()], ['uuid' => 'b', 'failed_at' => now()->subDays(3)]]);

    expect(app(Reporter::class)->build()->toArray()['health']['failed_jobs_24h'])->toBe(1);
});

function failedJobsTable(): void
{
    config(['queue.failed.driver' => 'database-uuids', 'queue.failed.table' => 'failed_jobs', 'queue.failed.database' => 'testing']);
    Schema::create('failed_jobs', function ($table) {
        $table->id();
        $table->string('uuid');
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at');
    });
}

function failedJob(string $job, string $exception, $failedAt): array
{
    return [
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode(['uuid' => 'x', 'displayName' => $job, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => ['command' => str_repeat('x', 5000)]]),
        'exception' => $exception."\nStack trace:\n#0 /var/www/vendor/laravel/framework/src/Foo.php(10): bar()\n".str_repeat('#1 frame'."\n", 500),
        'failed_at' => $failedAt,
    ];
}

it('groups failed jobs by job and exception without messages by default', function () {
    failedJobsTable();
    $base = base_path();
    DB::table('failed_jobs')->insert([
        failedJob('App\\Jobs\\SendInvoice', "Symfony\\Component\\Mailer\\Exception\\TransportException: Mail to jane@example.com failed in {$base}/app/Jobs/SendInvoice.php:42", now()->subHours(2)),
        failedJob('App\\Jobs\\SendInvoice', "Symfony\\Component\\Mailer\\Exception\\TransportException: Mail to bob@example.com failed in {$base}/app/Jobs/SendInvoice.php:42", now()->subHour()),
        failedJob('App\\Jobs\\SyncOrders', 'RuntimeException: Order 12345678 not found in /elsewhere/src/Api.php:7', now()->subMinutes(5)),
        failedJob('App\\Jobs\\Old', 'RuntimeException: old in /x.php:1', now()->subDays(2)),
    ]);

    $health = app(Reporter::class)->build()->toArray()['health'];

    expect($health['failed_jobs_24h'])->toBe(3)
        ->and($health['failed_jobs_groups'])->toHaveCount(2)
        ->and($health['failed_jobs_groups'][0])->toMatchArray([
            'job' => 'App\\Jobs\\SendInvoice',
            'exception' => 'Symfony\\Component\\Mailer\\Exception\\TransportException',
            'location' => 'app/Jobs/SendInvoice.php:42',
            'count' => 2,
            'queue' => 'default',
            'connection' => 'redis',
        ])
        ->and($health['failed_jobs_groups'][0])->not->toHaveKey('message')
        ->and($health['failed_jobs_groups'][1]['location'])->toBe('Api.php:7')
        ->and(json_encode($health))->not->toContain('example.com')->not->toContain('12345678')->not->toContain('/elsewhere');

    $this->artisan('porro:status')->expectsOutputToContain('App\\Jobs\\SendInvoice × 2')->assertSuccessful();
});

it('sends redacted first lines of messages when turned on', function () {
    failedJobsTable();
    config(['porro.failed_jobs.messages' => true]);
    DB::table('failed_jobs')->insert([
        failedJob('App\\Jobs\\SyncOrders', "RuntimeException: Order 12345678 for jane.doe@example.co.uk failed\nsecond line in /x/Api.php:7", now()->subMinutes(5)),
    ]);

    $group = app(Reporter::class)->build()->toArray()['health']['failed_jobs_groups'][0];

    expect($group['message'])->toBe('Order [number] for [email] failed')
        ->and($group['location'])->toBe('Api.php:7');
});

it('finds the throw location in paths with spaces and keeps paths out of messages', function () {
    expect(LaravelCollector::exception('RuntimeException: Disk full in /var/www/my app/app/Jobs/Export.php:12'))
        ->toBe(['class' => 'RuntimeException', 'message' => 'Disk full', 'location' => '/var/www/my app/app/Jobs/Export.php:12'])
        ->and(LaravelCollector::exception('RuntimeException: Can\'t open C:\\Users\\Jane Doe\\in.csv in C:\\Program Files\\My App\\app\\Jobs\\Import.php:7'))
        ->toBe(['class' => 'RuntimeException', 'message' => 'Can\'t open C:\\Users\\Jane Doe\\in.csv', 'location' => 'C:\\Program Files\\My App\\app\\Jobs\\Import.php:7'])
        ->and(LaravelCollector::exception('LogicException in /srv/app/x.php:3'))
        ->toBe(['class' => 'LogicException', 'message' => null, 'location' => '/srv/app/x.php:3'])
        ->and(LaravelCollector::redact('Can\'t write /home/forge/my site/storage/x.log or C:\\Program Files\\App\\y.txt, see https://example.org/docs'))
        ->toBe('Can\'t write [path] or [path] see https://example.org/docs');
});

it('sends the throw location and a path-free message for an app in a folder with spaces', function () {
    failedJobsTable();
    config(['porro.failed_jobs.messages' => true]);
    $this->app->setBasePath('/var/www/my app');
    DB::table('failed_jobs')->insert([
        failedJob('App\\Jobs\\Export', 'RuntimeException: Can\'t open /var/www/my app/storage/export.csv in /var/www/my app/app/Jobs/Export.php:12', now()->subMinute()),
    ]);

    $group = app(Reporter::class)->build()->toArray()['health']['failed_jobs_groups'][0];

    expect($group['location'])->toBe('app/Jobs/Export.php:12')
        ->and($group['message'])->toBe('Can\'t open [path]')
        ->and(json_encode($group))->not->toContain('/var/www');
});

it('reads the start of each payload and exception with SQL Server\'s SUBSTRING', function () {
    $connection = new SqlServerConnection(fn () => throw new RuntimeException('not connected'), 'porro', '', ['name' => 'sqlsrv', 'driver' => 'sqlsrv']);

    $sql = LaravelCollector::groupsQuery($connection->table('failed_jobs'))->toSql();

    expect($sql)->toContain('SUBSTRING([payload], 1, 1000) as [payload_head]')
        ->toContain('SUBSTRING([exception], 1, 2000) as [exception_head]')
        ->toContain('top 500');
});

it('sends no groups when nothing failed', function () {
    failedJobsTable();

    $health = app(Reporter::class)->build()->toArray()['health'];

    expect($health['failed_jobs_24h'])->toBe(0)->and($health['failed_jobs_groups'])->toBeNull();
});

it('parses stored exceptions and payloads defensively', function () {
    expect(LaravelCollector::exception('Illuminate\\Queue\\MaxAttemptsExceededException: App\\Jobs\\X has been attempted too many times.'))
        ->toBe(['class' => 'Illuminate\\Queue\\MaxAttemptsExceededException', 'message' => 'App\\Jobs\\X has been attempted too many times.', 'location' => null])
        ->and(LaravelCollector::exception('garbage!'))->toBe(['class' => null, 'message' => null, 'location' => null])
        ->and(LaravelCollector::jobName('{"uuid":"a","displayName":"App\\\\Jobs\\\\Cut'))->toBe('Unknown job')
        ->and(LaravelCollector::jobName('{"displayName":"App\\\\Jobs\\\\Ok","job":'))->toBe('App\\Jobs\\Ok')
        ->and(LaravelCollector::redact(str_repeat('a', 300)))->toHaveLength(160);
});

it('sends packages only when the lock file changed or Porro asks', function () {
    $key = siteKey()['token'];
    config(['porro.key' => $key]);
    Http::fake(['porro.test/*' => Http::sequence()->push(['ok' => true], 202)->push(['ok' => true], 202)->push(['ok' => true, 'need_lock' => true], 202)->push(['ok' => true], 202)]);

    $reporter = app(Reporter::class);
    $reporter->send();
    $reporter->send();
    Cache::forget('porro:lock-hash'); // as if Porro lost it
    $reporter->send(withPackages: false);

    $bodies = collect(Http::recorded())->map(fn ($pair) => json_decode($pair[0]->body(), true));

    expect($bodies[0]['lock']['packages'])->toBeArray()
        ->and($bodies[1]['lock']['packages'])->toBeNull();
});

it('reports the npm lock file too, sending its packages only when it changed or Porro asks', function () {
    $key = siteKey()['token'];
    config(['porro.key' => $key]);
    file_put_contents(base_path('package.json'), json_encode(['dependencies' => ['alpinejs' => '^3.14'], 'devDependencies' => ['vite' => '^5.4']]));
    file_put_contents(base_path('package-lock.json'), json_encode(['lockfileVersion' => 3, 'packages' => [
        '' => [],
        'node_modules/alpinejs' => ['version' => '3.14.1'],
        'node_modules/vite' => ['version' => '5.4.8', 'dev' => true],
    ]]));
    Http::fake(['porro.test/*' => Http::sequence()
        ->push(['ok' => true], 202)
        ->push(['ok' => true], 202)
        ->push(['ok' => true, 'need_npm_lock' => true], 202)
        ->push(['ok' => true], 202)]);

    try {
        $reporter = app(Reporter::class);
        $reporter->send();
        $reporter->send();
        $reporter->send(); // Porro has lost it and asks

    } finally {
        @unlink(base_path('package.json'));
        @unlink(base_path('package-lock.json'));
    }

    $bodies = collect(Http::recorded())->map(fn ($pair) => json_decode($pair[0]->body(), true));

    expect($bodies[0]['npm_lock']['packages'])->toBe([
        ['name' => 'alpinejs', 'version' => '3.14.1', 'dev' => false, 'direct' => true],
        ['name' => 'vite', 'version' => '5.4.8', 'dev' => true, 'direct' => true],
    ])
        ->and($bodies[1]['npm_lock']['packages'])->toBeNull()
        ->and($bodies[1]['npm_lock']['hash'])->toBe($bodies[0]['npm_lock']['hash'])
        // Asked for the packages, the third report is sent again with them.
        ->and($bodies)->toHaveCount(4)
        ->and($bodies[2]['npm_lock']['packages'])->toBeNull()
        ->and($bodies[3]['npm_lock']['packages'])->toBeArray();
});

it('says when a site has no npm lock file', function () {
    config(['porro.key' => siteKey()['token']]);
    Http::fake(['porro.test/*' => Http::response(['ok' => true], 202)]);

    app(Reporter::class)->send();

    expect(json_decode(Http::recorded()[0][0]->body(), true))->toHaveKey('npm_lock', null);
});

it('connects, writes the key to .env and sends the first report', function () {
    $env = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($env, "APP_NAME=Test\n");
    $this->app->loadEnvironmentFrom(basename($env));
    $this->app->useEnvironmentPath(dirname($env));
    $key = siteKey()['token'];
    Http::fake(['porro.test/*' => Http::response(['ok' => true], 202)]);

    $this->artisan('porro:connect', ['key' => $key])->assertSuccessful();

    expect(file_get_contents($env))->toContain("PORRO_KEY={$key}");
    Http::assertSent(fn (Request $request) => $request->url() === 'https://porro.test/api/v1/reports' && $request->hasHeader('X-Porro-Signature'));
});

it('explains a bad key instead of sending', function () {
    Http::fake();

    $this->artisan('porro:connect', ['key' => 'lk_site_nope'])->assertFailed();

    Http::assertNothingSent();
});

it('reports on the schedule once a key is set', function () {
    config(['porro.key' => siteKey()['token'], 'porro.interval' => 15]);

    $events = collect(app(Schedule::class)->events());

    $porro = $events->filter(fn ($e) => str_contains((string) $e->command.$e->description, 'porro'));

    // One task only, so environments that scale to zero can sleep between reports.
    expect($porro)->toHaveCount(1)
        ->and($porro->first()->expression)->toBe('*/15 * * * *')
        ->and((string) $porro->first()->command)->toContain('porro:report')->toContain('--scheduled');
});

it('records the heartbeat when the scheduler sends a report', function () {
    config(['porro.key' => siteKey()['token']]);
    Http::fake(['porro.test/*' => Http::response(['ok' => true], 202)]);

    $this->artisan('porro:report')->assertSuccessful();
    expect(Heartbeat::lastRun())->toBeNull();

    $this->artisan('porro:report', ['--scheduled' => true])->assertSuccessful();
    expect(Heartbeat::lastRun())->not->toBeNull();
});
