<?php

namespace Porro\Laravel\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Porro\Core\Cron;
use Porro\Core\CronResult;
use Porro\Core\CrontabIo;
use Porro\Core\SiteKey;
use Porro\Laravel\Heartbeat;
use Porro\Laravel\Reporter;

class ConnectCommand extends Command
{
    protected $signature = 'porro:connect
        {key : The site key from Porro}
        {--url= : Only if you run Porro somewhere other than app.porro.dev}
        {--cron : Add the scheduler to this server\'s crontab without asking}
        {--no-cron : Don\'t touch the crontab}';

    protected $description = 'Connect this site to Porro and send the first report';

    public function handle(Reporter $reporter): int
    {
        $key = (string) $this->argument('key');
        $url = $this->option('url') ? rtrim((string) $this->option('url'), '/') : null;

        try {
            SiteKey::parse($key);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $result = $reporter->send($key, $url ?? (string) config('porro.url'), withPackages: true, trigger: 'connect');

        if (! $result->ok) {
            $this->components->error("Porro didn’t accept the report: {$result->error}");

            return self::FAILURE;
        }

        $written = $this->writeEnv(array_filter(['PORRO_KEY' => $key, 'PORRO_URL' => $url]));

        $this->components->info('Connected. Porro has the first report.');

        if (! $written) {
            $this->components->warn('Couldn’t write .env. Add these to the site’s environment:');
            $this->line("  PORRO_KEY={$key}");
            if ($url) {
                $this->line("  PORRO_URL={$url}");
            }
        }

        $this->setUpCron();

        $this->components->bulletList([
            'Add `php artisan porro:report` to your deploy script so Porro hears about deploys straight away.',
        ]);

        return self::SUCCESS;
    }

    /**
     * Reports run on Laravel's scheduler, which needs one cron line. Add it
     * for the agency when the server allows, otherwise show it to copy.
     */
    protected function setUpCron(): void
    {
        $command = $this->schedulerCommand();
        $directory = $this->laravel->basePath();
        $line = Cron::line('* * * * *', $directory, $command);
        $io = $this->laravel->make(CrontabIo::class);

        $lastRun = Heartbeat::lastRun();
        if ($lastRun !== null && strtotime($lastRun) >= time() - 300) {
            $this->components->info('The scheduler is running, so reports will go every '.config('porro.interval').' minutes.');

            return;
        }

        $crontab = Cron::current($io);
        if ($crontab !== null && Cron::hasEntry($crontab, $directory, $command)) {
            $this->components->info('The scheduler is already in this server’s crontab. Reports will go every '.config('porro.interval').' minutes.');

            return;
        }

        $install = match (true) {
            (bool) $this->option('no-cron') => false,
            (bool) $this->option('cron') => true,
            $crontab === null, ! $this->input->isInteractive() => false,
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

    /**
     * @param  array<string, string>  $values
     */
    private function writeEnv(array $values): bool
    {
        $path = $this->laravel->environmentFilePath();

        if (! is_file($path) || ! is_writable($path)) {
            return false;
        }

        $env = (string) file_get_contents($path);

        foreach ($values as $name => $value) {
            $line = "{$name}={$value}";
            $env = preg_match("/^{$name}=.*$/m", $env)
                ? (string) preg_replace("/^{$name}=.*$/m", $line, $env)
                : rtrim($env, "\n")."\n{$line}\n";
        }

        return file_put_contents($path, $env) !== false;
    }
}
