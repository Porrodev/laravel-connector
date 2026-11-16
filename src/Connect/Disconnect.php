<?php

namespace Porro\Laravel\Connect;

use Illuminate\Contracts\Foundation\Application;
use Porro\Core\Client;
use Porro\Core\SiteKey;
use Porro\Laravel\Reporter;
use Porro\Laravel\SiteHealth;
use Porro\Laravel\SiteKeys;
use Throwable;

/**
 * Undoes "Connect to Porro": tells Porro, signed with the site's key, then
 * deletes the key from this site's database. The key goes whatever Porro
 * answered, because the person asked for the site to stop reporting; when
 * Porro didn't answer it shows the site as silent instead of disconnected.
 *
 * Only the stored key can be removed here. PORRO_KEY in the environment is
 * not this code's to change.
 */
class Disconnect
{
    public function __construct(private Application $app, private SiteKeys $keys, private Reporter $reporter) {}

    /**
     * Whether there is a key this site can remove by itself.
     */
    public function available(): bool
    {
        return $this->keys->source() === SiteKeys::STORED;
    }

    /**
     * @return array{workspace: string|null, host: string|null, told: bool}|null null when there was nothing to disconnect
     */
    public function disconnect(): ?array
    {
        if (! $this->available()) {
            return null;
        }

        $stored = $this->keys->stored();
        $told = false;

        try {
            $result = (new Client(
                SiteKey::parse((string) $this->keys->token()),
                (string) config('porro.url'),
                $this->reporter->transport(),
                retries: 1,
            ))->disconnect();

            // A key Porro already turns away is as disconnected as it gets.
            $told = $result->ok || $result->keyTurnedAway();
        } catch (Throwable) {
            // A key that can't be read can still be deleted.
        }

        $this->keys->forget();
        $this->reporter->forgetSent();
        $this->app->make(SiteHealth::class)->forget();

        return [
            'workspace' => $stored['workspace'] ?? null,
            'host' => $stored['host'] ?? null,
            'told' => $told,
        ];
    }
}
