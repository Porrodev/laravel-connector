<?php

namespace Porro\Laravel;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Written every minute by the scheduler, so Porro can tell the scheduler
 * is running even between reports.
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
