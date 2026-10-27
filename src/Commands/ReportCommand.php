<?php

namespace Porro\Laravel\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Porro\Laravel\Heartbeat;
use Porro\Laravel\Reporter;
use Porro\Laravel\Reporting;

class ReportCommand extends Command
{
    protected $signature = 'porro:report
        {--packages : Send the full package list even if composer.lock is unchanged}
        {--scheduled : Set by the scheduler, so Porro knows cron is running}
        {--deploy : Set by the deploy script: reports the deploy and never fails it}';

    protected $description = 'Send a report to Porro now (also runs on the schedule and is safe to add to your deploy script)';

    public function handle(Reporter $reporter): int
    {
        if ($reason = Reporting::offReason()) {
            $this->components->info("Porro reporting is off: {$reason}.");

            return self::SUCCESS;
        }

        if (! config('porro.key')) {
            $this->components->warn('No PORRO_KEY set. Run `php artisan porro:connect <site key>` first.');

            return $this->failed();
        }

        if ($this->option('scheduled')) {
            Heartbeat::beat();
        }

        try {
            $result = $reporter->send(
                withPackages: (bool) $this->option('packages'),
                trigger: match (true) {
                    (bool) $this->option('scheduled') => 'schedule',
                    (bool) $this->option('deploy') => 'deploy',
                    default => 'manual',
                },
            );
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return $this->failed();
        }

        if (! $result->ok) {
            $this->components->error("Porro didn’t accept the report: {$result->error}");

            return $this->failed();
        }

        $this->components->info('Report sent to Porro.');

        return self::SUCCESS;
    }

    /**
     * A report that can't be sent never fails a deploy.
     */
    private function failed(): int
    {
        return $this->option('deploy') ? self::SUCCESS : self::FAILURE;
    }
}
