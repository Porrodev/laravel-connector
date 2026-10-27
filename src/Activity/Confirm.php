<?php

namespace Porro\Laravel\Activity;

/**
 * What the block and safelist buttons ask before they act. Each says what
 * will happen to whom and for how long, so a misclick costs a "Cancel".
 * Shared so every panel asks the same thing.
 */
final class Confirm
{
    public static function block(string $target, int $days = 0): string
    {
        $who = str_contains($target, '/')
            ? __('Every request from the :count addresses in :range gets a 403, including any real visitors in it.', ['count' => self::size($target), 'range' => $target])
            : __('Every request from it gets a 403.');

        return $days > 0
            ? __('Block :target for :days days?', ['target' => $target, 'days' => $days]).' '.$who.' '.__('It’s lifted automatically after that.')
            : __('Block :target permanently?', ['target' => $target]).' '.$who.' '.__('It stays blocked until someone removes it.');
    }

    public static function safelist(string $ip): string
    {
        return __('Safelist :ip?', ['ip' => $ip]).' '.__('It will never be blocked or flagged here, even if it keeps probing. Remove it from the safelist on the Blocking page.');
    }

    /** Addresses in a range, readable: "256", "65,536", or "many" for IPv6. */
    private static function size(string $range): string
    {
        [$ip, $bits] = explode('/', $range, 2);

        if (str_contains($ip, ':')) {
            return __('many');
        }

        return number_format(2 ** (32 - max(0, min(32, (int) $bits))));
    }
}
