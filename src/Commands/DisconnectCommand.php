<?php

namespace Porro\Laravel\Commands;

use Illuminate\Console\Command;
use Porro\Laravel\Connect\Disconnect;
use Porro\Laravel\Reporting;
use Porro\Laravel\SiteKeys;

class DisconnectCommand extends Command
{
    protected $signature = 'porro:disconnect
        {--force : Don\'t ask first}
        {--live : This is the live site, whatever APP_ENV says}';

    protected $description = 'Disconnect this site from Porro and delete its key';

    public function handle(SiteKeys $keys, Disconnect $disconnect): int
    {
        if ($keys->source() === SiteKeys::ENV) {
            $this->components->warn('This site is connected with PORRO_KEY in its environment, which this command can’t change. Remove PORRO_KEY there and deploy.');

            return self::FAILURE;
        }

        if (! $disconnect->available()) {
            $this->components->info('This site isn’t connected to Porro.');

            return self::SUCCESS;
        }

        $stored = $keys->stored();
        $site = $stored['host'] ?? 'this site';
        $from = $stored['workspace'] ?? 'Porro';

        // A staging or local copy of the live database holds the live
        // site's key: disconnecting here would switch the live site off.
        if (! Reporting::production() && ! $this->option('live')) {
            $this->components->warn('This copy’s environment is “'.$this->laravel->environment().'”, not the live site. Disconnecting here would switch off the live site’s key in Porro too. Run it on the live site, or add --live if this really is it.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Disconnect {$site} from {$from}?")) {
            return self::SUCCESS;
        }

        $result = $disconnect->disconnect();

        if ($result === null) {
            $this->components->info('This site isn’t connected to Porro.');

            return self::SUCCESS;
        }

        if ($result['told']) {
            $this->components->info('Disconnected. Porro has been told.');
        } else {
            $this->components->warn('Disconnected on this site. Porro didn’t answer, so it hasn’t been told: it will show this site as not reporting.');
        }

        $this->line('  The key has been deleted from this site’s database.');
        $this->line('  The local checks still run. Connect again from the control panel, or with porro:connect.');

        return self::SUCCESS;
    }
}
