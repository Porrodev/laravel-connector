<?php

namespace Porro\Laravel;

use Porro\Core\Report;

/**
 * Adds facts to a report. Layers such as Filament and Statamic register
 * their own collectors with the "porro.collectors" tag.
 */
interface Collector
{
    public function collect(Report $report): void;
}
