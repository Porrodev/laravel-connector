<?php

use Illuminate\Auth\Events\Failed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Porro\Core\Activity\Recorder;
use Porro\Laravel\Activity\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app['env'] = 'production';
    Http::fake(['*' => Http::response('', 500)]);
    // No lottery: only the minute rule and the pages flush.
    config(['porro.activity.flush_odds' => PHP_INT_MAX]);
});

function scanFrom(string $ip): array
{
    return ['REMOTE_ADDR' => $ip];
}

function bufferFile(): string
{
    return app(Activity::class)->buffer()->path;
}

/** This server flushed just now, so the next requests only buffer. */
function justFlushed(): void
{
    is_dir(dirname(bufferFile())) || mkdir(dirname(bufferFile()), 0775, true);
    touch(bufferFile().'.flushed');
}

function stored(string $ip): mixed
{
    return Activity::store()->get('porro:activity:'.gmdate('YmdH').':ip:'.$ip);
}

describe('buffered activity', function () {
    it('only appends to this server’s buffer on a suspicious request', function () {
        justFlushed();

        $this->withServerVariables(scanFrom('185.220.101.4'))->get('/.env')->assertNotFound();
        $this->withServerVariables(scanFrom('185.220.101.4'))->get('/wp-login.php');

        expect(stored('185.220.101.4'))->toBeNull()
            ->and(file(bufferFile()))->toHaveCount(2);
    });

    it('costs an ordinary visitor nothing', function () {
        Route::get('/', fn () => 'home');

        $this->withServerVariables(scanFrom('81.2.69.160'))->get('/')->assertOk();

        expect(file_exists(bufferFile()))->toBeFalse()
            ->and(file_exists(bufferFile().'.flushed'))->toBeFalse();
    });

    it('flushes after the response once this server hasn’t for a minute', function () {
        justFlushed();
        $this->withServerVariables(scanFrom('185.220.101.4'))->get('/.env');
        touch(bufferFile().'.flushed', time() - 61);

        $this->withServerVariables(scanFrom('185.220.101.4'))->get('/.env');

        expect(stored('185.220.101.4')['q'])->toBe(2)
            ->and(file_exists(bufferFile()))->toBeFalse();
    });

    it('flushes now and then by lottery, after the response, but not more than every 5 seconds', function () {
        justFlushed();
        config(['porro.activity.flush_odds' => 1]);

        $this->withServerVariables(scanFrom('185.220.101.4'))->get('/.env');
        expect(stored('185.220.101.4'))->toBeNull();

        touch(bufferFile().'.flushed', time() - 6);
        $this->withServerVariables(scanFrom('185.220.101.4'))->get('/.env');

        expect(stored('185.220.101.4')['q'])->toBe(2);
    });

    it('flushes every minute from the scheduler, on every server', function () {
        justFlushed();
        $this->withServerVariables(scanFrom('185.220.101.4'))->get('/.env');

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'porro:activity-flush');

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('* * * * *')
            ->and($event->onOneServer)->toBeFalse();

        $event->run(app());

        expect(stored('185.220.101.4')['q'])->toBe(1);
    });

    it('flushes before the Activity page, the IP page and Site health read activity', function (string $page, string $see) {
        $this->app['env'] = 'local';
        config(['porro.activity.enabled' => true]);
        justFlushed();

        foreach (range(1, 3) as $i) {
            $this->withServerVariables(scanFrom('185.220.101.4'))->get('/.env');
        }

        expect(stored('185.220.101.4'))->toBeNull();

        $this->get($page)->assertOk()->assertSee($see);

        expect(stored('185.220.101.4')['q'])->toBe(3);
    })->with([
        'activity' => ['/porro/activity', '185.220.101.4'],
        'ip' => ['/porro/activity/185.220.101.4', 'first seen here'],
        'site health' => ['/porro', 'Site health'],
    ]);

    it('records straight away when the folder can’t be written', function () {
        $dir = sys_get_temp_dir().'/porro-readonly-'.bin2hex(random_bytes(4));
        mkdir($dir, 0555);
        config(['porro.activity.buffer' => "{$dir}/porro/activity.buf"]);

        $this->withServerVariables(scanFrom('185.220.101.4'))->get('/.env');

        expect(stored('185.220.101.4')['q'])->toBe(1);

        chmod($dir, 0755);
    });

    it('records straight away when the buffer is turned off', function () {
        config(['porro.activity.buffer' => false]);

        $this->withServerVariables(scanFrom('185.220.101.4'))->get('/.env');

        expect(stored('185.220.101.4')['q'])->toBe(1);
    });

    it('buffers failed logins with the username hashed', function () {
        justFlushed();
        Route::post('/login', function () {
            event(new Failed('web', null, ['email' => 'alice@example.com', 'password' => 'x']));

            return response('no', 422);
        });

        $this->withServerVariables(scanFrom('185.220.101.4'))->post('/login');

        expect(file_get_contents(bufferFile()))->not->toContain('alice')
            ->and(app(Activity::class)->summary()->totals[Recorder::LOGIN_FAILED])->toBe(1);
    });

    it('deletes what an earlier setting left once batching is off', function () {
        $storage = sys_get_temp_dir().'/porro-storage-'.bin2hex(random_bytes(4));
        $this->app->useStoragePath($storage);
        config(['porro.activity.buffer' => null]);
        justFlushed();
        $this->withServerVariables(scanFrom('185.220.101.4'))->get('/.env');
        $file = bufferFile();
        expect($file)->toBe("{$storage}/porro/activity.buf")->and(file_exists($file))->toBeTrue();
        config(['porro.activity.buffer' => false]);

        app(Activity::class)->maintain();

        expect(file_exists($file))->toBeFalse();
    });

    it('records straight away on Lambda, where storage goes with the container', function () {
        putenv('AWS_LAMBDA_FUNCTION_NAME=vapor-app');

        try {
            expect(app(Activity::class)->buffer())->toBeNull();
        } finally {
            putenv('AWS_LAMBDA_FUNCTION_NAME');
        }
    });
});
