<?php

namespace Porro\Laravel\Collectors;

use Porro\Core\Report;
use Porro\Laravel\Collector;
use Throwable;

/**
 * The latest results from spatie/laravel-health, when it's installed.
 * Only check names, statuses and short summaries are sent.
 */
class HealthCollector implements Collector
{
    public function collect(Report $report): void
    {
        $store = 'Spatie\\Health\\ResultStores\\ResultStore';

        if (! interface_exists($store) || ! app()->bound($store)) {
            return;
        }

        try {
            $results = app($store)->latestResults();

            foreach ($results?->storedCheckResults ?? [] as $result) {
                $report->check([
                    'name' => (string) ($result->label ?: $result->name),
                    'status' => (string) $result->status,
                    'summary' => mb_substr((string) $result->shortSummary, 0, 120),
                ]);
            }
        } catch (Throwable) {
            // Never let a reporting problem break the site.
        }
    }
}
