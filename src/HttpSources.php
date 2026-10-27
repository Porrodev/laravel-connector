<?php

namespace Porro\Laravel;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Porro\Core\Health\Sources;
use Throwable;

/**
 * Core's Sources on Laravel's cache and HTTP client, so Http::fake()
 * works in tests.
 */
class HttpSources
{
    public static function make(): Sources
    {
        return new Sources(
            fn (string $key) => Cache::get($key),
            fn (string $key, mixed $value, int $ttl) => Cache::put($key, $value, $ttl),
            self::transport(),
        );
    }

    /**
     * Streamed, so it stops reading once a response is over the limit.
     *
     * @return Closure(string, string, array<string, string>, string|null, int, int): array{0: int, 1: string}
     */
    public static function transport(): Closure
    {
        return function (string $method, string $url, array $headers, ?string $body, int $timeout, int $maxBytes = Sources::MAX_BYTES): array {
            try {
                $request = Http::withHeaders($headers)->timeout($timeout)->withOptions(['stream' => true]);

                if ($body !== null) {
                    $request = $request->withBody($body, $headers['Content-Type'] ?? 'application/json');
                }

                $response = $request->send($method, $url);
                $stream = $response->toPsrResponse()->getBody();
                $data = '';

                while (! $stream->eof() && strlen($data) <= $maxBytes) {
                    $data .= $stream->read(min(65536, $maxBytes + 1 - strlen($data)));
                }

                return [$response->status(), $data];
            } catch (Throwable $e) {
                return [0, $e->getMessage()];
            }
        };
    }
}
