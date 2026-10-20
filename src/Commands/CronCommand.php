<?php

namespace Porro\Laravel\Commands;

use Illuminate\Console\Command;
use Porro\Laravel\Commands\Concerns\SetsUpCron;

/**
 * Adds the scheduler's cron line on its own, without the site key:
 * for when connect ran somewhere else, or cron was skipped.
 */
class CronCommand extends Command
{
    use SetsUpCron;

    protected $signature = 'porro:cron';

    protected $description = 'Add Laravel’s scheduler to this server’s crontab so Porro reports keep coming';

    public function handle(): int
    {
        $this->setUpCron(ask: false, force: true);

        return self::SUCCESS;
    }
}
