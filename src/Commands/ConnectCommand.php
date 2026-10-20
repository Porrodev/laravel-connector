<?php

namespace Porro\Laravel\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Porro\Core\SiteKey;
use Porro\Laravel\Reporter;

class ConnectCommand extends Command
{
    protected $signature = 'porro:connect {key : The site key from Porro} {--url= : Only if you run Porro somewhere other than app.porro.dev}';

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

        $result = $reporter->send($key, $url ?? (string) config('porro.url'), withPackages: true);

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

        $this->components->bulletList([
            'Reports run every '.config('porro.interval').' minutes on the scheduler. Make sure `schedule:run` is in cron.',
            'Add `php artisan porro:report` to your deploy script so Porro hears about deploys straight away.',
        ]);

        return self::SUCCESS;
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
