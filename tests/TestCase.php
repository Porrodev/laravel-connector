<?php

namespace Porro\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Porro\Core\CrontabIo;
use Porro\Laravel\PorroServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PorroServiceProvider::class];
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
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('porro.url', 'https://porro.test');

        // Testbench runs as "testing", which would otherwise turn reporting off.
        $app['config']->set('porro.enabled', true);

        // Never touch the real crontab of whoever runs the tests.
        $app->instance(CrontabIo::class, new FakeCrontab(works: false));
    }
}
