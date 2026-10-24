<?php

namespace Porro\Laravel\Collectors;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Porro\Core\Database;
use Porro\Core\Report;
use Porro\Laravel\Collector;
use Porro\Laravel\Heartbeat;
use Throwable;

/**
 * Laravel facts: version, environment, debug mode (as true/false only),
 * scheduler heartbeat, failed jobs, pending migrations and the default
 * database connection's engine and version.
 */
class LaravelCollector implements Collector
{
    /** Failed jobs read to build the groups: the newest ones in the last day. */
    private const FAILED_JOBS_SCANNED = 500;

    private const FAILED_JOBS_GROUPS = 5;

    public function __construct(private Application $app) {}

    public function collect(Report $report): void
    {
        $report->platform($this->app->version(), $this->app->environment());

        $report->health('debug', (bool) config('app.debug'));
        $report->health('scheduler_last_run', Heartbeat::lastRun());
        $report->health('report_interval', max(1, min(60, (int) config('porro.interval'))));
        $report->health('failed_jobs_24h', $failed = $this->failedJobs());
        $report->health('failed_jobs_groups', $failed ? $this->failedJobGroups() : null);
        $report->health('pending_migrations', $this->pendingMigrations());
        $report->database($this->database());
    }

    /**
     * @return array{engine: string, version: string|null, local: bool|null, managed: string|null}|null
     */
    private function database(): ?array
    {
        try {
            $connection = DB::connection();
            $driver = (string) $connection->getDriverName();

            // PDO's own string: Laravel's getServerVersion() drops "MariaDB" from it.
            $version = $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

            // A read/write split lists hosts; a socket wins over the host for MySQL.
            $host = $connection->getConfig('host');
            $host = is_array($host) ? reset($host) : $host;
            $socket = in_array($driver, ['mysql', 'mariadb'], true) ? $connection->getConfig('unix_socket') : null;

            return Database::describe($driver, is_scalar($version) ? (string) $version : null, is_string($socket) && $socket !== '' ? $socket : (string) $host);
        } catch (Throwable) {
            return null;
        }
    }

    private function failedJobs(): ?int
    {
        try {
            $table = $this->failedJobsTable();

            return $table?->where('failed_at', '>=', now()->subDay())->count();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The last day's failed jobs, grouped by job and exception class, from
     * the newest FAILED_JOBS_SCANNED rows. Only the start of each payload and
     * exception is read, so a huge table or a long stack trace stays cheap.
     * No exception message unless porro.failed_jobs.messages is on, and then
     * only its first line, cut short, with emails and long numbers redacted.
     *
     * @return list<array{job: string, exception: string|null, location: string|null, message?: string|null, count: int, last_failed_at: string, queue: string|null, connection: string|null}>|null
     */
    private function failedJobGroups(): ?array
    {
        try {
            $table = $this->failedJobsTable();

            if (! $table) {
                return null;
            }

            $rows = $table->where('failed_at', '>=', now()->subDay())
                ->orderByDesc('failed_at')
                ->limit(self::FAILED_JOBS_SCANNED)
                ->get([
                    'connection',
                    'queue',
                    'failed_at',
                    DB::raw('substr(payload, 1, 1000) as payload_head'),
                    DB::raw('substr(exception, 1, 2000) as exception_head'),
                ]);

            $messages = (bool) config('porro.failed_jobs.messages', false);
            $groups = [];

            foreach ($rows as $row) {
                $job = self::jobName((string) $row->payload_head);
                $thrown = self::exception((string) $row->exception_head);
                $key = $job."\0".$thrown['class'];

                if (! isset($groups[$key])) {
                    $groups[$key] = array_filter([
                        'job' => $job,
                        'exception' => $thrown['class'],
                        'location' => $thrown['location'] ? $this->relative($thrown['location']) : null,
                        'message' => $messages ? self::redact($thrown['message']) : false,
                        'count' => 0,
                        // Newest first, so the first row seen is the last to fail.
                        'last_failed_at' => Carbon::parse($row->failed_at)->toIso8601String(),
                        'queue' => is_string($row->queue) ? $row->queue : null,
                        'connection' => is_string($row->connection) ? $row->connection : null,
                    ], fn ($value) => $value !== false);
                }

                $groups[$key]['count']++;
            }

            usort($groups, fn ($a, $b) => $b['count'] <=> $a['count']);

            return array_slice($groups, 0, self::FAILED_JOBS_GROUPS);
        } catch (Throwable) {
            return null;
        }
    }

    private function failedJobsTable(): ?Builder
    {
        $table = config('queue.failed.table', 'failed_jobs');
        $connection = config('queue.failed.database');

        if (! in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true) || ! Schema::connection($connection)->hasTable($table)) {
            return null;
        }

        return DB::connection($connection)->table($table);
    }

    /**
     * The job's display name from the start of its payload JSON (the whole
     * payload may be cut short, so it's matched rather than decoded).
     */
    public static function jobName(string $payload): string
    {
        if (preg_match('/"displayName"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $payload, $match)) {
            $name = json_decode('"'.$match[1].'"');

            if (is_string($name) && $name !== '') {
                return mb_substr($name, 0, 200);
            }
        }

        return 'Unknown job';
    }

    /**
     * Class, message and throw location from a stored exception, which is
     * PHP's "Class: message in /path/File.php:12\nStack trace:..." string.
     *
     * @return array{class: string|null, message: string|null, location: string|null}
     */
    public static function exception(string $exception): array
    {
        $head = explode("\nStack trace:", $exception, 2)[0];

        if (! preg_match('/^([A-Za-z_][\w\\\\]*)(?::\s?(.*?))?(?: in (\S+:\d+))?\s*$/s', $head, $match)) {
            return ['class' => null, 'message' => null, 'location' => null];
        }

        return [
            'class' => mb_substr($match[1], 0, 200),
            'message' => ($match[2] ?? '') !== '' ? $match[2] : null,
            'location' => ($match[3] ?? '') !== '' ? $match[3] : null,
        ];
    }

    /**
     * The message's first line, at most 160 characters, with email addresses
     * and runs of six or more digits (phone, card, order numbers) redacted.
     */
    public static function redact(?string $message): ?string
    {
        $line = trim(strtok((string) $message, "\r\n") ?: '');

        if ($line === '') {
            return null;
        }

        $line = (string) preg_replace('/[^\s@<>"\'()]+@[^\s@<>"\'()]+\.[^\s@<>"\'()]+/u', '[email]', $line);
        $line = (string) preg_replace('/\d[\d \-]{4,}\d/', '[number]', $line);

        return mb_strlen($line) > 160 ? rtrim(mb_substr($line, 0, 159)).'…' : $line;
    }

    /**
     * The throw location relative to the app, so server paths stay put.
     */
    private function relative(string $location): string
    {
        $base = rtrim($this->app->basePath(), '/').'/';

        return str_starts_with($location, $base) ? substr($location, strlen($base)) : basename($location);
    }

    private function pendingMigrations(): ?int
    {
        try {
            /** @var Migrator $migrator */
            $migrator = $this->app->make('migrator');

            if (! $migrator->repositoryExists()) {
                return null;
            }

            $files = $migrator->getMigrationFiles(array_merge([$this->app->databasePath('migrations')], $migrator->paths()));
            $ran = $migrator->getRepository()->getRan();

            return count(array_diff(array_keys($files), $ran));
        } catch (Throwable) {
            return null;
        }
    }
}
