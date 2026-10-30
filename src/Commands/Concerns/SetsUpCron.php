<?php

namespace Porro\Laravel\Commands\Concerns;

use Illuminate\Console\Command;
use Porro\Core\Cron;
use Porro\Core\CronResult;
use Porro\Core\CrontabIo;
use Porro\Laravel\Heartbeat;

/**
 * Shared by porro:connect and porro:cron.
 *
 * @mixin Command
 */
trait SetsUpCron
{
    /**
     * Reports, the scheduler check and activity batching run on Laravel's
     * scheduler, which needs one cron line. Add it for the agency when the
     * server allows, otherwise show it to copy. The messages only talk
     * about reports once the site is connected.
     *
     * Returns whether the scheduler runs, or is now in the crontab.
     */
    protected function setUpCron(bool $ask, bool $skip = false, bool $force = false, bool $connected = true): bool
    {
        $interval = config('porro.interval');

        $command = $this->schedulerCommand();
        $directory = $this->laravel->basePath();
        $line = Cron::line('* * * * *', $directory, $command);
        $io = $this->laravel->make(CrontabIo::class);

        $lastRun = Heartbeat::lastRun();
        if ($lastRun !== null && strtotime($lastRun) >= time() - (((int) config('porro.interval') + 5) * 60)) {
            $this->components->info($connected
                ? "The scheduler is running, so reports will go every {$interval} minutes."
                : 'The scheduler is running, so the site health checks and activity stay up to date.');

            return true;
        }

        if (Cron::isScheduled($directory, $command, $io) === true) {
            $this->components->info($connected
                ? 'Found the scheduler in this server’s cron. The first scheduled report is due by '.$this->nextReportAt().", then every {$interval} minutes."
                : 'Found the scheduler in this server’s cron.');

            return true;
        }

        $crontab = Cron::current($io);

        $install = match (true) {
            $skip => false,
            $force => true,
            ! $ask, $crontab === null, ! $this->input->isInteractive() => false,
            default => $this->components->confirm('Reports run on the scheduler, which needs one cron line. Add it to this server’s crontab now?', true),
        };

        $result = $install ? Cron::install($line, $directory, $command, $io) : null;

        if ($result === CronResult::Installed || $result === CronResult::AlreadyInstalled) {
            $this->components->info('Added the scheduler to this server’s crontab.'.($connected ? " Reports will go every {$interval} minutes." : ''));

            return true;
        }

        if ($result === CronResult::Failed || $result === CronResult::Unavailable) {
            $this->components->warn('Couldn’t update the crontab on this server.');
        }

        $this->components->warn('One step left: add this cron line so '.($connected ? 'reports keep coming' : 'the scheduler runs').'. In Forge or Ploi, add it as a scheduled job; anywhere else, run `crontab -e`.');
        $this->line("  {$line}");
        $this->newLine();

        return false;
    }

    /**
     * When the scheduler next runs porro:report, e.g. "14:45".
     */
    protected function nextReportAt(): string
    {
        $interval = max(1, min(60, (int) config('porro.interval')));
        $minutes = (int) floor(time() / 60);

        return date('H:i', (intdiv($minutes, $interval) + 1) * $interval * 60);
    }

    /**
     * What cron runs from the project folder.
     */
    protected function schedulerCommand(): string
    {
        return 'artisan schedule:run';
    }
}
