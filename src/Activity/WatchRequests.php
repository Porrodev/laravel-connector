<?php

namespace Porro\Laravel\Activity;

use Closure;
use Illuminate\Http\Request;
use Porro\Core\Activity\Probes;
use Porro\Core\Activity\Recorder;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Global middleware, first in line: turns blocked IPs away with a plain
 * 403 before the app does any work, and after the response has gone,
 * records requests that look like an attack (a line in this server's
 * buffer, flushed now and then).
 *
 * Global, not in the web group, because a scanner's requests mostly hit
 * no route at all, and route middleware never sees a 404.
 */
class WatchRequests
{
    public function __construct(private Activity $activity) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            if ($this->activity->enforcing() && $this->activity->blocked($this->activity->clientIp($request)) !== null) {
                return new Response('Forbidden', 403, ['Content-Type' => 'text/plain', 'X-Porro-Blocked' => '1']);
            }
        } catch (Throwable) {
            // Fail open: a broken blocklist never takes the site down.
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (! $this->activity->recording()) {
                return;
            }

            $ip = $this->activity->clientIp($request);
            $recorder = $this->activity->recorder();
            // The method sent, not a _method override: a form can't hide a flood.
            $method = $request->getRealMethod();
            $path = $request->getPathInfo();
            $host = self::host($request);
            $agent = $request->userAgent();
            $proxied = $this->activity->viaCloudflare($request);

            if ($response->headers->has('X-Porro-Blocked')) {
                $recorder->turnedAway($ip, $method, $path, $host, $agent, $proxied);
                $this->activity->buffered();

                return;
            }

            $country = $this->activity->country($request);
            $status = $response->getStatusCode();
            $category = $this->activity->probes()->match($path);
            $more = ['country' => $country, 'userAgent' => $agent, 'host' => $host, 'proxied' => $proxied];

            $suspicious = true;

            if ($category !== null) {
                $recorder->record($ip, Recorder::PROBE, $method, $path, $status, ...[...$more, 'category' => $category]);

                if (Probes::exposes($category) && $status >= 200 && $status < 300) {
                    $recorder->exposed($ip, $path, $status);
                }
            } elseif ($status === 404) {
                $recorder->record($ip, Recorder::NOT_FOUND, $method, $path, $status, ...$more);
            } elseif ($method === 'POST' && ! $request->is(...$this->ignoredPosts())) {
                $recorder->record($ip, Recorder::POST_FLOOD, $method, $path, $status, ...$more);
            } elseif ($this->activity->watching($ip)) {
                // Flagged already: count the rest, so its page shows them all.
                $recorder->seen($ip, $method, $path, $status, $host, $agent, $proxied);
            } else {
                $suspicious = false;
            }

            if ($suspicious) {
                $this->activity->buffered();
            }
        } catch (Throwable) {
            //
        } finally {
            $this->flush();
        }

        // Expired rows are cleared now and then even without the scheduler,
        // after recording, so a failed tidy never costs an event.
        try {
            if (random_int(1, 500) === 1 && $this->activity->recording()) {
                $this->activity->tidyStore();
            }
        } catch (Throwable) {
            //
        }
    }

    /**
     * Records the buffer now and then, once a request has added to it
     * (here or a failed login): an ordinary page view never pays for it.
     */
    private function flush(): void
    {
        try {
            $this->activity->flushIfDue();
        } catch (Throwable) {
            //
        }
    }

    /**
     * The Host it asked for, or null: an invalid host, or one trustHosts()
     * rejects, throws, and must never stop the request being recorded.
     */
    private static function host(Request $request): ?string
    {
        try {
            return $request->getHost();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function ignoredPosts(): array
    {
        return array_values(array_map('strval', (array) config('porro.activity.ignore_posts', [])));
    }
}
