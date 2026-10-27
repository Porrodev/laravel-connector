<?php

namespace Porro\Laravel\Http;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;
use Porro\Core\Activity\Blocklist;
use Porro\Core\Activity\Entry;
use Porro\Core\Activity\Refused;
use Porro\Core\Health\Issue;
use Porro\Laravel\Activity\Activity;
use Porro\Laravel\Activity\Presenter;
use Porro\Laravel\SiteHealth;
use Throwable;

/**
 * The /porro page: Site health, Activity and Blocking, like Horizon's
 * dashboard but for the site's health. Every rule about what may be
 * blocked lives in core's Blocklist; this only shows its answers.
 */
class PageController extends Controller
{
    public const SEVERITIES = [
        Issue::CRITICAL => ['label' => 'Act now', 'tone' => 'critical'],
        Issue::WARNING => ['label' => 'Attention', 'tone' => 'warning'],
        Issue::NOTICE => ['label' => 'Notice', 'tone' => 'notice'],
    ];

    public function __construct(private SiteHealth $health, private Activity $activity, private Presenter $presenter) {}

    public function health(Request $request): View
    {
        return $this->page($request, 'health');
    }

    public function activity(Request $request): View
    {
        return $this->page($request, 'activity');
    }

    public function blocking(Request $request): View
    {
        return $this->page($request, 'blocking', $this->presenter->blocking() + [
            'prefill' => filter_var($request->query('safelist'), FILTER_VALIDATE_IP) ?: null,
        ]);
    }

    /**
     * Setting up nginx: the two one-time steps for each server, and how far
     * each server has got.
     */
    public function nginx(Request $request): View
    {
        $blocking = $this->presenter->blocking();

        return $this->page($request, 'nginx', [
            'enforcement' => $blocking['enforcement'],
            'behindCloudflare' => $blocking['cloudflare']['configured'] || $this->activity->ranges()->isCloudflare((string) $request->server('REMOTE_ADDR')),
            'sharedCache' => ! in_array(config('cache.default'), ['file', 'array', 'apc'], true),
        ]);
    }

    public function refresh(): RedirectResponse
    {
        $this->health->get(fresh: true);

        return redirect()->route('porro.health')->with('success', __('Checked again.'));
    }

    public function block(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ip' => ['required', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:255'],
            'days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'suggested' => ['nullable', 'boolean'],
        ]);

        $days = (int) ($data['days'] ?? 0);

        return $this->change($request, fn (Blocklist $list) => $list->block(
            $data['ip'],
            ($data['reason'] ?? '') ?: __('Blocked on the Porro page'),
            ($data['suggested'] ?? false) ? Entry::SUGGESTED : Entry::MANUAL,
            $days > 0 ? time() + $days * 86400 : null,
            $this->who($request),
            $this->activity->clientIp($request),
        ), fn (Entry $entry) => __('Blocked :ip.', ['ip' => $entry->cidr]));
    }

    public function unblock(Request $request): RedirectResponse
    {
        $ip = (string) $request->validate(['ip' => ['required', 'string', 'max:64']])['ip'];

        return $this->change($request, fn (Blocklist $list) => $list->unblock($ip), fn () => __('Unblocked :ip.', ['ip' => $ip]));
    }

    public function safelist(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ip' => ['required', 'string', 'max:64'],
            'label' => ['nullable', 'string', 'max:255'],
            'confirm_wide' => ['nullable', 'boolean'],
        ]);

        return $this->change($request, fn (Blocklist $list) => $list->safelist(
            $data['ip'],
            (string) ($data['label'] ?? ''),
            null,
            $this->who($request),
            (bool) ($data['confirm_wide'] ?? false),
        ), fn (Entry $entry) => __('Safelisted :ip.', ['ip' => $entry->cidr]));
    }

    public function unsafelist(Request $request): RedirectResponse
    {
        $ip = (string) $request->validate(['ip' => ['required', 'string', 'max:64']])['ip'];

        return $this->change($request, fn (Blocklist $list) => $list->unsafelist($ip), fn () => __('Removed :ip from the safelist.', ['ip' => $ip]));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function page(Request $request, string $tab, array $extra = []): View
    {
        try {
            $health = $this->health->get();
        } catch (Throwable $e) {
            report($e);
            $health = null;
        }

        $porro = $health['porro'] ?? null;

        return view("porro-laravel::page.{$tab}", $extra + [
            'tab' => $tab,
            'failed' => $health === null,
            'site' => config('app.name'),
            'environment' => app()->environment(),
            'connected' => (bool) ($health['connected'] ?? false),
            'porroOk' => (bool) ($porro['ok'] ?? false),
            'porroError' => $porro['error'] ?? null,
            'porroUrl' => is_string($porro['site_url'] ?? null) && str_starts_with($porro['site_url'], 'https://') ? $porro['site_url'] : null,
            'found' => $this->present($porro['findings'] ?? []),
            'local' => $this->present($health['issues'] ?? []),
            'counts' => $health['counts'] ?? ['critical' => 0, 'warning' => 0, 'notice' => 0],
            'checked' => $health['checked'] ?? [],
            'unavailable' => $health['unavailable'] ?? [],
            'checkedAgo' => isset($health['checked_at']) ? Carbon::parse($health['checked_at'])->diffForHumans() : null,
            'commands' => $health['commands'] ?? [],
            'activity' => $this->presenter->activity($health['activity'] ?? null),
            'canManage' => Authorize::canManage($request),
            'backUrl' => url('/'),
        ]);
    }

    /**
     * Applies a change, then refreshes what depends on the lists: the
     * request-time copy, the nginx file and the cached health.
     */
    private function change(Request $request, Closure $change, Closure $message): RedirectResponse
    {
        abort_unless(Authorize::canManage($request), 403);

        try {
            $result = $change($this->activity->blocklist());
        } catch (Refused $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $this->activity->changed();

        try {
            if (config('porro.blocklist.nginx')) {
                Artisan::call('porro:blocklist-sync');
            }

            $this->health->forget();
        } catch (Throwable $e) {
            report($e);
        }

        return back()->with('success', $message($result));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function present(mixed $issues): array
    {
        if (! is_array($issues)) {
            return [];
        }

        return array_values(array_map(function ($issue) {
            $issue = Issue::fromArray(is_array($issue) ? $issue : [])->toArray();

            return $issue + [
                'style' => self::SEVERITIES[$issue['severity']] ?? self::SEVERITIES[Issue::NOTICE],
                'link' => is_string($issue['url']) && str_starts_with($issue['url'], 'https://') ? $issue['url'] : null,
            ];
        }, array_filter($issues, fn ($i) => is_array($i) && ($i['title'] ?? '') !== '')));
    }

    private function who(Request $request): ?string
    {
        $user = $request->user();

        return $user ? (string) ($user->name ?? $user->email ?? $user->getAuthIdentifier()) : 'local';
    }
}
