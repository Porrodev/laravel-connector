<?php

namespace Porro\Laravel\Commands;

use Illuminate\Console\Command;
use Porro\Core\Activity\Cloudflare;
use Porro\Core\Activity\CloudflareError;
use Porro\Laravel\Activity\CloudflareMode;

class CloudflareCommand extends Command
{
    protected $signature = 'porro:cloudflare
        {action=status : status, connect, sync or disconnect}';

    protected $description = 'Block at Cloudflare\'s edge: connect the zone, sync the blocklist, or disconnect';

    public function handle(CloudflareMode $cloudflare): int
    {
        if (! $cloudflare->configured()) {
            $this->components->warn('Set PORRO_CLOUDFLARE_TOKEN first: a token with Zone WAF Rules (Edit) and Zone (Read) for the site\'s domain, and Account Rule Lists (Edit) for the account. The Blocking page\'s Cloudflare setup walks through it.');

            return self::FAILURE;
        }

        try {
            return match ($this->argument('action')) {
                'connect' => $this->connect($cloudflare),
                'sync' => $this->sync($cloudflare),
                'disconnect' => $this->disconnect($cloudflare),
                default => $this->status($cloudflare),
            };
        } catch (CloudflareError $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function connect(CloudflareMode $cloudflare): int
    {
        $connection = $cloudflare->connect();

        $this->components->info("Connected to {$connection['zone_name']}: blocks go to the ".Cloudflare::LIST_NAME.' list, with a rule blocking it on this zone.');

        return $this->sync($cloudflare);
    }

    private function sync(CloudflareMode $cloudflare): int
    {
        $result = $cloudflare->sync();

        if ($result === null) {
            $status = $cloudflare->status();
            $this->components->warn($status['production'] ? ($status['error'] ?? 'Didn’t sync.') : 'Only production syncs to Cloudflare: a copy of the site shouldn’t change the list every site on the account shares.');

            return $status['production'] ? self::FAILURE : self::SUCCESS;
        }

        $this->components->info("Synced: {$result['added']} added, {$result['removed']} removed".($result['shared'] ? ", {$result['shared']} already blocked by another site" : '').'.');

        return self::SUCCESS;
    }

    private function disconnect(CloudflareMode $cloudflare): int
    {
        $cloudflare->disconnect();
        $this->components->info('Disconnected: this site’s items and the rule are gone from Cloudflare.');

        return self::SUCCESS;
    }

    private function status(CloudflareMode $cloudflare): int
    {
        $status = $cloudflare->status();

        $this->components->twoColumnDetail('Zone', $status['zone'] ?? 'not connected (php artisan porro:cloudflare connect)');
        $this->components->twoColumnDetail('Syncs', $status['production'] ? 'yes' : 'no, not production');
        $this->components->twoColumnDetail('Last sync', $status['synced_at'] ? date('j M H:i', $status['synced_at']) : '—');
        $this->components->twoColumnDetail('Last error', $status['error'] ?? '—');

        return self::SUCCESS;
    }
}
