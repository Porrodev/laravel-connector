<?php

namespace Porro\Laravel;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Written each time the scheduler runs porro:report, so Porro can tell
 * the site's scheduler is running.
 */
final class Heartbeat
{
    private const KEY = 'porro:scheduler:last-run';

    public static function beat(): void
    {
        try {
            Cache::forever(self::KEY, now()->toIso8601String());
        } catch (Throwable) {
            //
        }
    }

    public static function lastRun(): ?string
    {
        try {
            return Cache::get(self::KEY);
        } catch (Throwable) {
            return null;
        }
    }
}
