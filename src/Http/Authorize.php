<?php

namespace Porro\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may open /porro: anyone in the local environment; anywhere else,
 * only users your "viewPorro" gate allows.
 * With no gate defined, production is closed to everyone.
 *
 *     Gate::define('viewPorro', fn (User $user) => in_array($user->email, ['ops@youragency.com']));
 *
 * Changing the blocklist also checks "managePorroBlocklist" when you
 * define it; otherwise viewing is enough.
 *
 * Connecting the site to Porro from the page needs "connectPorro", and
 * is closed until you define it: viewing is never enough.
 *
 *     Gate::define('connectPorro', fn (User $user) => $user->is_admin);
 */
class Authorize
{
    public const VIEW = 'viewPorro';

    public const MANAGE = 'managePorroBlocklist';

    public const CONNECT = 'connectPorro';

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(self::canView($request), 403);

        return $next($request);
    }

    public static function canView(Request $request): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        // A guest never passes: Gate denies them unless the gate's closure
        // takes a nullable user on purpose.
        return Gate::has(self::VIEW) && Gate::forUser($request->user())->allows(self::VIEW);
    }

    public static function canManage(Request $request): bool
    {
        if (! self::canView($request)) {
            return false;
        }

        return ! Gate::has(self::MANAGE) || Gate::forUser($request->user())->allows(self::MANAGE);
    }

    /**
     * Connecting gives this site a key: never on viewing alone.
     */
    public static function canConnect(Request $request): bool
    {
        // Always a signed-in person, even where a gate lets guests through
        // (or locally, where viewing is open): the request to connect is
        // tied to whoever started it.
        $user = $request->user();

        if ($user === null || (string) $user->getAuthIdentifier() === '') {
            return false;
        }

        return self::canView($request) && Gate::has(self::CONNECT) && Gate::forUser($user)->allows(self::CONNECT);
    }
}
