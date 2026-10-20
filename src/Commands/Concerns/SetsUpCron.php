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
     * Reports run on Laravel's scheduler, which needs one cron line. Add it
     * for the agency when the server allows, otherwise show it to copy.
     */
    protected function setUpCron(bool $ask, bool $skip = false, bool $force = false): void
    {
        $command = $this->schedulerCommand();
        $directory = $this->laravel->basePath();
        $line = Cron::line('* * * * *', $directory, $command);
        $io = $this->laravel->make(CrontabIo::class);

        $lastRun = Heartbeat::lastRun();
        if ($lastRun !== null && strtotime($lastRun) >= time() - (((int) config('porro.interval') + 5) * 60)) {
            $this->components->info('The scheduler is running, so reports will go every '.config('porro.interval').' minutes.');

            return;
        }

        $crontab = Cron::current($io);
        if ($crontab !== null && Cron::hasEntry($crontab, $directory, $command)) {
            $this->components->info('The scheduler is already in this server’s crontab. Reports will go every '.config('porro.interval').' minutes.');

            return;
        }

        $install = match (true) {
            $skip => false,
            $force => true,
            ! $ask, $crontab === null, ! $this->input->isInteractive() => false,
            default => $this->components->confirm('Reports run on the scheduler, which needs one cron line. Add it to this server’s crontab now?', true),
        };

        $result = $install ? Cron::install($line, $directory, $command, $io) : null;

        if ($result === CronResult::Installed || $result === CronResult::AlreadyInstalled) {
            $this->components->info('Added the scheduler to this server’s crontab. Reports will go every '.config('porro.interval').' minutes.');

            return;
        }

        if ($result === CronResult::Failed || $result === CronResult::Unavailable) {
            $this->components->warn('Couldn’t update the crontab on this server.');
        }

        $this->components->warn('One step left: add this cron line so reports keep coming. In Forge or Ploi, add it as a scheduled job; anywhere else, run `crontab -e`.');
        $this->line("  {$line}");
        $this->newLine();
    }

    /**
     * What cron runs from the project folder.
     */
    protected function schedulerCommand(): string
    {
        return 'artisan schedule:run';
    }
}
