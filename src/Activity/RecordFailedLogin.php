<?php

namespace Porro\Laravel\Activity;

use Illuminate\Auth\Events\Failed;
use Porro\Core\Activity\Recorder;
use Throwable;

/**
 * Counts failed sign-ins on any guard: the app's own, Statamic's control
 * panel or Filament's. The username is kept only as a keyed hash.
 */
class RecordFailedLogin
{
    public function __construct(private Activity $activity) {}

    public function handle(Failed $event): void
    {
        try {
            if (! $this->activity->recording() || ! app()->bound('request')) {
                return;
            }

            $request = request();

            $this->activity->recorder()->record(
                $this->activity->clientIp($request),
                Recorder::LOGIN_FAILED,
                $request->getMethod(),
                $request->getPathInfo(),
                401,
                $this->username($event->credentials),
                country: $this->activity->country($request),
            );
        } catch (Throwable) {
            //
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function username(array $credentials): ?string
    {
        foreach (['email', 'username', 'name', 'login'] as $key) {
            if (isset($credentials[$key]) && is_string($credentials[$key])) {
                return $credentials[$key];
            }
        }

        foreach ($credentials as $key => $value) {
            if ($key !== 'password' && is_string($value)) {
                return $value;
            }
        }

        return null;
    }
}
