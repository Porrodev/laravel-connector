<?php

namespace Porro\Laravel\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Porro\Core\Report;
use Porro\Laravel\Commands\Concerns\SetsUpCron;
use Porro\Laravel\Reporter;
use Porro\Laravel\Reporting;

/**
 * Adds the scheduler's cron line on its own, without the site key:
 * for when connect ran somewhere else, or cron was skipped.
 */
class CronCommand extends Command
{
    use SetsUpCron;

    protected $signature = 'porro:cron';

    protected $description = 'Add Laravel’s scheduler to this server’s crontab, for the site health checks, activity and Porro reports';

    public function handle(Reporter $reporter): int
    {
        $connected = filled(config('porro.key'));

        if ($this->setUpCron(ask: false, force: true, connected: $connected)) {
            $this->tellPorro($reporter, $connected);
        }

        return self::SUCCESS;
    }

    /**
     * Send a report straight away, as connect does, so Porro says
     * "checking" rather than "cron isn't set up" until the first
     * scheduled report. Never fails the command.
     */
    private function tellPorro(Reporter $reporter, bool $connected): void
    {
        if (! $connected) {
            $this->components->info('No PORRO_KEY set, so no report sent. Run `php artisan porro:connect <site key>` to connect.');

            return;
        }

        if (Reporting::offReason() !== null) {
            return;
        }

        try {
            $result = $reporter->send(trigger: Report::TRIGGER_CONNECT, health: ['scheduler_in_crontab' => true]);
        } catch (InvalidArgumentException $e) {
            $result = null;
            $error = $e->getMessage();
        }

        if ($result?->ok) {
            $this->components->info('Told Porro the scheduler is in cron.');

            return;
        }

        $this->components->warn('Couldn’t send a report to Porro: '.($result->error ?? $error ?? 'unknown error').'. The first scheduled report will tell it instead.');
    }
}
