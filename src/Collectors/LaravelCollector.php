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

            $rows = self::groupsQuery($table)->get();

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

    /**
     * The newest failed jobs in the last day, reading only the start of
     * each payload and exception. SQL Server spells substr SUBSTRING.
     */
    public static function groupsQuery(Builder $table): Builder
    {
        $grammar = $table->getGrammar();
        $substr = $table->getConnection()->getDriverName() === 'sqlsrv' ? 'SUBSTRING' : 'substr';
        $head = fn (string $column, int $length, string $as) => DB::raw("{$substr}({$grammar->wrap($column)}, 1, {$length}) as {$grammar->wrap($as)}");

        return $table->where('failed_at', '>=', now()->subDay())
            ->orderByDesc('failed_at')
            ->limit(self::FAILED_JOBS_SCANNED)
            ->select([
                'connection',
                'queue',
                'failed_at',
                $head('payload', 1000, 'payload_head'),
                $head('exception', 2000, 'exception_head'),
            ]);
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
        $head = rtrim(explode("\nStack trace:", $exception, 2)[0]);

        // An empty message leaves "Class in /path:12", with no colon.
        if (! preg_match('/^([A-Za-z_][\w\\\\]*)(?:(?::\s?|\s)(.*))?$/s', $head, $match)) {
            return ['class' => null, 'message' => null, 'location' => null];
        }

        $message = $match[2] ?? '';
        $location = null;

        // PHP ends with " in <path>:<line>"; the path may hold spaces
        // ("/var/www/my app/...", "C:\Program Files\..."), and the
        // message may mention other paths, so the last one wins.
        if (preg_match('/^(?:(.*)\s)?in ((?:[A-Za-z]:[\\\\\/]|\/)[^\n]*:\d+)$/s', $message, $in)) {
            [$message, $location] = [rtrim($in[1]), $in[2]];
        }

        return [
            'class' => mb_substr($match[1], 0, 200),
            'message' => $message !== '' ? $message : null,
            'location' => $location,
        ];
    }

    /**
     * The message's first line, at most 160 characters, with email
     * addresses, absolute file paths and runs of six or more digits
     * (phone, card, order numbers) redacted.
     */
    public static function redact(?string $message): ?string
    {
        $line = trim(strtok((string) $message, "\r\n") ?: '');

        if ($line === '') {
            return null;
        }

        $line = (string) preg_replace('/[^\s@<>"\'()]+@[^\s@<>"\'()]+\.[^\s@<>"\'()]+/u', '[email]', $line);
        // A path runs to the next space, plus any later words that carry on
        // with a slash: "/var/www/my app/x.php", "C:\Program Files\x.php".
        // Not URLs: the slash after "https:" doesn't start one.
        $line = (string) preg_replace('~(?:\b[A-Za-z]:[\\\\/]|(?<![\w:/.])/)[^\s\'"]*(?:\s+[^\s\'"]*[\\\\/][^\s\'"]*)*~', '[path]', $line);
        $line = (string) preg_replace('/\d[\d \-]{4,}\d/', '[number]', $line);

        return mb_strlen($line) > 160 ? rtrim(mb_substr($line, 0, 159)).'…' : $line;
    }

    /**
     * The throw location relative to the app, so server paths stay put.
     */
    private function relative(string $location): string
    {
        $base = rtrim($this->app->basePath(), '/\\').DIRECTORY_SEPARATOR;

        return str_starts_with($location, $base)
            ? str_replace('\\', '/', substr($location, strlen($base)))
            : (string) preg_replace('~^.*[\\\\/]~', '', $location);
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
