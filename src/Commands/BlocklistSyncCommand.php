<?php

namespace Porro\Laravel\Commands;

use Illuminate\Console\Command;
use Porro\Core\Activity\NginxBlocklist;
use Porro\Laravel\Activity\Activity;
use Throwable;

class BlocklistSyncCommand extends Command
{
    protected $signature = 'porro:blocklist-sync';

    protected $description = 'Tidy the blocklist and write the nginx deny file (runs every minute when PORRO_BLOCK_NGINX is on)';

    public function handle(Activity $activity): int
    {
        foreach ($activity->maintain() as $removed) {
            $this->components->twoColumnDetail("Removed {$removed['cidr']}", $removed['why']);
        }

        if (! config('porro.blocklist.nginx')) {
            return self::SUCCESS;
        }

        if (! $activity->isProduction()) {
            $this->components->info('Not writing the nginx file outside production.');

            return self::SUCCESS;
        }

        $path = (string) config('porro.blocklist.nginx_path');

        try {
            $changed = NginxBlocklist::write($path, NginxBlocklist::render($activity->blocklist()->blocks()));
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info($changed ? "Wrote {$path}" : 'The nginx file is up to date.');

        return self::SUCCESS;
    }
}
