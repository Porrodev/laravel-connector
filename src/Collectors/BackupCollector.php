<?php

namespace Porro\Laravel\Collectors;

use Porro\Core\Report;
use Porro\Laravel\Collector;
use Throwable;

/**
 * Newest healthy backup from spatie/laravel-backup, when it's installed.
 */
class BackupCollector implements Collector
{
    public function collect(Report $report): void
    {
        $factory = 'Spatie\\Backup\\Tasks\\Monitor\\BackupDestinationStatusFactory';

        if (! class_exists($factory) || ! config('backup.monitor_backups')) {
            return;
        }

        $report->health('backup_configured', true);

        try {
            $newest = collect($factory::createForMonitorConfig(config('backup.monitor_backups')))
                ->map(fn ($status) => $status->backupDestination()->newestBackup()?->date())
                ->filter()
                ->max();

            $report->health('last_backup_at', $newest?->toIso8601String());
        } catch (Throwable) {
            $report->health('last_backup_at', null);
        }
    }
}
