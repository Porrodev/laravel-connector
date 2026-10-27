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
 * records requests that look like an attack.
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
        if ($response->headers->has('X-Porro-Blocked')) {
            return;
        }

        try {
            if (! $this->activity->recording()) {
                return;
            }

            $ip = $this->activity->clientIp($request);
            $country = $this->activity->country($request);
            $method = $request->getMethod();
            $path = $request->getPathInfo();
            $status = $response->getStatusCode();
            $recorder = $this->activity->recorder();
            $category = $this->activity->probes()->match($path);

            if ($category !== null) {
                $recorder->record($ip, Recorder::PROBE, $method, $path, $status, country: $country, userAgent: $request->userAgent());

                if (Probes::exposes($category) && $status >= 200 && $status < 300) {
                    $recorder->exposed($ip, $path, $status);
                }

                return;
            }

            if ($status === 404) {
                $recorder->record($ip, Recorder::NOT_FOUND, $method, $path, $status, country: $country, userAgent: $request->userAgent());
            } elseif ($method === 'POST' && ! $request->is(...$this->ignoredPosts())) {
                $recorder->record($ip, Recorder::POST_FLOOD, $method, $path, $status, country: $country, userAgent: $request->userAgent());
            }
        } catch (Throwable) {
            //
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
