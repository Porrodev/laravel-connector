<?php

namespace Porro\Laravel;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Written each time the scheduler runs porro:report, so Porro can tell
 * the site's scheduler is running.
 *
 * Kept in a file rather than the cache, because deploys run cache:clear
 * and would otherwise make the next report say the scheduler never ran.
 */
final class Heartbeat
{
    private const FILE = 'framework/porro-heartbeat';

    /** Where connector versions before the file kept it. */
    private const LEGACY_KEY = 'porro:scheduler:last-run';

    public static function beat(): void
    {
        try {
            self::write(self::path(), now()->toIso8601String());
        } catch (Throwable) {
            //
        }
    }

    public static function lastRun(): ?string
    {
        try {
            $path = self::path();

            if (is_file($path) && ($contents = trim((string) @file_get_contents($path))) !== '') {
                return $contents;
            }
        } catch (Throwable) {
            //
        }

        try {
            return Cache::get(self::LEGACY_KEY);
        } catch (Throwable) {
            return null;
        }
    }

    private static function path(): string
    {
        return storage_path(self::FILE);
    }

    /**
     * Write through a temporary file and rename it into place, so a report
     * read mid-write never sees half a timestamp.
     */
    private static function write(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create {$directory}.");
        }

        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

        try {
            if (@file_put_contents($temporary, $contents) === false || ! @rename($temporary, $path)) {
                throw new RuntimeException("Cannot write {$path}.");
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
