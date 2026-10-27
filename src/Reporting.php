<?php

namespace Porro\Laravel;

/**
 * Whether this copy of the site reports to Porro.
 *
 * PORRO_ENABLED wins when it's set. Left unset, only production reports, so
 * a local or staging copy with the live .env never poses as the live site.
 */
class Reporting
{
    public static function enabled(): bool
    {
        return self::offReason() === null;
    }

    /**
     * Why reporting is off, or null when it's on.
     */
    public static function offReason(): ?string
    {
        $setting = self::setting();

        if ($setting === false) {
            return 'PORRO_ENABLED=false';
        }

        if ($setting === null && ! app()->environment('production')) {
            return 'not production (APP_ENV='.app()->environment().'). Set PORRO_ENABLED=true to report from here';
        }

        return null;
    }

    /**
     * The explicit setting, or null when PORRO_ENABLED isn't set.
     */
    private static function setting(): ?bool
    {
        $value = config('porro.enabled');

        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }
}
