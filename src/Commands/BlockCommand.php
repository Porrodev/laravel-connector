<?php

namespace Porro\Laravel\Commands;

use Illuminate\Console\Command;
use Porro\Core\Activity\Entry;
use Porro\Core\Activity\Refused;
use Porro\Laravel\Activity\Activity;

class BlockCommand extends Command
{
    protected $signature = 'porro:block
        {ip : An IP address or range, e.g. 203.0.113.7 or 203.0.113.0/24}
        {--reason= : Why, shown in the blocklist}
        {--days= : Remove it after this many days (default: never)}
        {--remove : Unblock it instead}';

    protected $description = 'Block an IP address or range, or unblock it with --remove';

    public function handle(Activity $activity): int
    {
        $list = $activity->blocklist();
        $ip = (string) $this->argument('ip');

        if ($this->option('remove')) {
            $list->unblock($ip);
            $activity->changed();
            $this->components->info("Unblocked {$ip}.");

            return self::SUCCESS;
        }

        $days = $this->option('days');

        try {
            $entry = $list->block(
                $ip,
                (string) ($this->option('reason') ?: 'Blocked from the command line'),
                Entry::MANUAL,
                is_numeric($days) ? time() + (int) $days * 86400 : null,
                'command line',
            );
        } catch (Refused $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $activity->changed();
        $this->components->info("Blocked {$entry->cidr}".($entry->expiresAt ? ' until '.date('j M Y H:i', $entry->expiresAt) : '').'.');

        return self::SUCCESS;
    }
}
