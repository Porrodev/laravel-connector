<?php

namespace Porro\Laravel\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Porro\Laravel\Connect\ConnectFailure;
use Porro\Laravel\Connect\PanelConnect;

/**
 * "Connect to Porro" for the /porro page: start sends the browser to Porro
 * to approve, and the return route finishes it. Both need the
 * "connectPorro" gate, not just viewing. Anything that goes wrong lands
 * back on /porro with the reason in words.
 */
class ConnectController extends Controller
{
    public function start(Request $request, PanelConnect $connect): RedirectResponse
    {
        abort_unless(Authorize::canConnect($request), 403);

        try {
            return redirect()->away($connect->start($request, route('porro.connect.return', [], false), self::user($request)));
        } catch (ConnectFailure $e) {
            return redirect()->route('porro.health')->with('error', $e->getMessage());
        }
    }

    public function return(Request $request, PanelConnect $connect): RedirectResponse
    {
        abort_unless(Authorize::canConnect($request), 403);

        // Porro's own page sends the person back here when they're done.
        if (! $connect->returning($request)) {
            return redirect()->route('porro.health');
        }

        try {
            $next = $connect->finish($request, self::user($request));
        } catch (ConnectFailure $e) {
            return redirect()->route('porro.health')->with('error', $e->getMessage());
        }

        return $next !== null
            ? redirect()->away($next)
            : redirect()->route('porro.health')->with('success', __('Connected to Porro. The first report has been sent.'));
    }

    private static function user(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
