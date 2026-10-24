<?php

namespace Porro\Laravel\Collectors;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Migrations\Migrator;
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
    public function __construct(private Application $app) {}

    public function collect(Report $report): void
    {
        $report->platform($this->app->version(), $this->app->environment());

        $report->health('debug', (bool) config('app.debug'));
        $report->health('scheduler_last_run', Heartbeat::lastRun());
        $report->health('report_interval', max(1, min(60, (int) config('porro.interval'))));
        $report->health('failed_jobs_24h', $this->failedJobs());
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
            $table = config('queue.failed.table', 'failed_jobs');
            $connection = config('queue.failed.database');

            if (! in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true) || ! Schema::connection($connection)->hasTable($table)) {
                return null;
            }

            return DB::connection($connection)->table($table)->where('failed_at', '>=', now()->subDay())->count();
        } catch (Throwable) {
            return null;
        }
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
