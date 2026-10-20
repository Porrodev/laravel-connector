<?php

namespace Porro\Laravel\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Porro\Laravel\Heartbeat;
use Porro\Laravel\Reporter;

class ReportCommand extends Command
{
    protected $signature = 'porro:report
        {--packages : Send the full package list even if composer.lock is unchanged}
        {--scheduled : Set by the scheduler, so Porro knows cron is running}';

    protected $description = 'Send a report to Porro now (also runs on the schedule and is safe to add to your deploy script)';

    public function handle(Reporter $reporter): int
    {
        if (! config('porro.enabled')) {
            $this->components->info('Porro reporting is turned off (PORRO_ENABLED=false).');

            return self::SUCCESS;
        }

        if (! config('porro.key')) {
            $this->components->warn('No PORRO_KEY set. Run `php artisan porro:connect <site key>` first.');

            return self::FAILURE;
        }

        if ($this->option('scheduled')) {
            Heartbeat::beat();
        }

        try {
            $result = $reporter->send(
                withPackages: (bool) $this->option('packages'),
                trigger: $this->option('scheduled') ? 'schedule' : 'manual',
            );
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $result->ok) {
            $this->components->error("Porro didn’t accept the report: {$result->error}");

            return self::FAILURE;
        }

        $this->components->info('Report sent to Porro.');

        return self::SUCCESS;
    }
}
