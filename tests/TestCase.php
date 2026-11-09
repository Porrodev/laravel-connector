<?php

namespace Porro\Laravel\Tests;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Middleware\TrustProxies;
use Orchestra\Testbench\TestCase as Orchestra;
use Porro\Core\CrontabIo;
use Porro\Laravel\PorroServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PorroServiceProvider::class];
    }

    /**
     * Laravel 10's skeleton has the app's own TrustProxies in the global
     * stack, but testbench 8 leaves it out: put it back, so the stack
     * looks like a real Laravel 10 app's.
     */
    protected function resolveApplicationHttpKernel($app)
    {
        parent::resolveApplicationHttpKernel($app);

        if (! method_exists(TrustProxies::class, 'at')) {
            $app->extend(HttpKernel::class, fn ($kernel) => $kernel->prependMiddleware(TrustProxies::class));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The heartbeat lives in testbench's shared storage folder, so one
        // test's beat would otherwise leak into the next.
        $this->forgetHeartbeat();
        $this->beforeApplicationDestroyed(fn () => $this->forgetHeartbeat());
    }

    private function forgetHeartbeat(): void
    {
        @unlink(storage_path('framework/porro-heartbeat'));
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('porro.url', 'https://porro.test');

        // A buffer of its own per test, so no line leaks into the next.
        $app['config']->set('porro.activity.buffer', sys_get_temp_dir().'/porro-test-'.bin2hex(random_bytes(6)).'/activity.buf');

        // Testbench runs as "testing", which would otherwise turn reporting off.
        $app['config']->set('porro.enabled', true);

        // Never touch the real crontab of whoever runs the tests.
        $app->instance(CrontabIo::class, new FakeCrontab(works: false));
    }
}
