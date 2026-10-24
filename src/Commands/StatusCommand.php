<?php

namespace Porro\Laravel\Commands;

use Illuminate\Console\Command;
use Porro\Laravel\Reporter;

class StatusCommand extends Command
{
    protected $signature = 'porro:status {--json : Print the exact report that would be sent}';

    protected $description = 'Show what this site would report to Porro, without sending anything';

    public function handle(Reporter $reporter): int
    {
        $report = $reporter->build()->toArray();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Connector', $report['connector']['name'].' '.$report['connector']['version']);
        $this->components->twoColumnDetail('Platform', ucfirst($report['platform']['name']).' '.$report['platform']['version'].' ('.$report['platform']['environment'].')');
        $this->components->twoColumnDetail('PHP', $report['php']['version']);

        foreach ($report['layers'] as $layer) {
            $this->components->twoColumnDetail(ucfirst($layer['name']), (string) $layer['version']);
        }

        foreach ($report['health'] as $key => $value) {
            if ($key === 'failed_jobs_groups') {
                foreach ($value ?? [] as $group) {
                    $this->components->twoColumnDetail('  '.$group['job'].' × '.$group['count'], trim(($group['exception'] ?? '').' '.($group['location'] ?? '')));
                }
            } elseif ($key !== 'checks') {
                $this->components->twoColumnDetail(str_replace('_', ' ', ucfirst($key)), is_bool($value) ? ($value ? 'yes' : 'no') : (string) ($value ?? '—'));
            }
        }

        $this->components->twoColumnDetail('composer.lock', $report['lock']['hash'] ?? 'not found');
        $this->components->twoColumnDetail('Key set', config('porro.key') ? 'yes' : 'no');

        return self::SUCCESS;
    }
}
