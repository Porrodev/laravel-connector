<?php

namespace Porro\Laravel\Activity;

/**
 * What the block and safelist buttons ask before they act: a title, what
 * will happen to whom and for how long, and a button named for the action,
 * so a misclick costs a "Cancel". Shared so every panel asks the same thing;
 * each panel draws the dialog in its own style.
 */
final class Confirm
{
    /**
     * @return array{title: string, body: string, button: string, danger: bool}
     */
    public static function block(string $target, int $days = 0): array
    {
        $range = str_contains($target, '/');

        $who = $range
            ? __('Every request from the :count addresses in :range gets a 403, including any real visitors in it.', ['count' => self::size($target), 'range' => $target])
            : __('Every request from it gets a 403.');

        return $days > 0 ? [
            'title' => __('Block :target for :days days?', ['target' => $target, 'days' => $days]),
            'body' => $who.' '.__('It’s lifted automatically after that.'),
            'button' => $range ? __('Block range for :days days', ['days' => $days]) : __('Block for :days days', ['days' => $days]),
            'danger' => true,
        ] : [
            'title' => __('Block :target permanently?', ['target' => $target]),
            'body' => $who.' '.__('It stays blocked until someone removes it.'),
            'button' => $range ? __('Block range permanently') : __('Block permanently'),
            'danger' => true,
        ];
    }

    /**
     * @return array{title: string, body: string, button: string, danger: bool}
     */
    public static function safelist(string $ip): array
    {
        return [
            'title' => __('Safelist :ip?', ['ip' => $ip]),
            'body' => __('It will never be blocked or flagged here, even if it keeps probing. Remove it from the safelist on the Blocking page.'),
            'button' => __('Safelist'),
            'danger' => false,
        ];
    }

    /**
     * For a form's data-porro-confirm attribute: the script opens the
     * panel's dialog with it before the form submits.
     *
     * @param  array{title: string, body: string, button: string, danger: bool}  $confirm
     */
    public static function attribute(array $confirm): string
    {
        return (string) json_encode($confirm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The script behind forms with data-porro-confirm. It opens the page's
     * <dialog id="porro-confirm"> and submits once confirmed. Without it, or
     * without the dialog, the form simply submits, so nothing breaks.
     */
    public static function script(): string
    {
        return (string) file_get_contents(__DIR__.'/../../resources/js/confirm.js');
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
