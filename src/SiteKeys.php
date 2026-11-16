<?php

namespace Porro\Laravel;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Porro\Core\SiteKey;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Where the site key comes from. PORRO_KEY in the environment wins, so a
 * site connected with `porro:connect` or its host's settings is unchanged.
 * Otherwise it's the key made by "Connect to Porro" in the control panel,
 * kept in the site's own database, encrypted with the app key.
 *
 * Everything that needs the key asks here, never config('porro.key').
 */
class SiteKeys
{
    public const TABLE = 'porro_settings';

    public const ENV = 'env';

    public const STORED = 'stored';

    private const ROW = 'site_key';

    private const PROBE = 'write_check';

    private const TURNED_AWAY = 'turned_away';

    /**
     * Who the stored key is connected to. Never the key itself: that is
     * read and decrypted each time it's needed, and kept nowhere.
     *
     * @var array{workspace: string|null, host: string|null, connected_at: string|null}|false|null
     */
    private array|false|null $stored = null;

    /**
     * The site key token, or null when the site isn't connected.
     */
    public function token(): ?string
    {
        return $this->fromEnv() ?? ($this->read()['token'] ?? null);
    }

    public function connected(): bool
    {
        return $this->source() !== null;
    }

    /**
     * "env" or "stored", or null when there's no key.
     */
    public function source(): ?string
    {
        return match (true) {
            $this->fromEnv() !== null => self::ENV,
            $this->stored() !== null => self::STORED,
            default => null,
        };
    }

    public function fromEnv(): ?string
    {
        $key = config('porro.key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    /**
     * Who the key made in the control panel is connected to, and since
     * when. Safe to show: the key is not in it (token() is the only way
     * to that). Null when there isn't one, the table isn't there yet, or
     * the app key has changed since and it can't be read.
     *
     * @return array{workspace: string|null, host: string|null, connected_at: string|null}|null
     */
    public function stored(): ?array
    {
        if ($this->stored === null) {
            $this->read();
        }

        return $this->stored ?: null;
    }

    /**
     * The stored row, decrypted. Only for token(): nothing keeps what
     * this returns.
     *
     * @return array{token: string}|null
     */
    private function read(): ?array
    {
        try {
            $value = DB::table(self::TABLE)->where('key', self::ROW)->value('value');
            $data = is_string($value) ? json_decode(Crypt::decryptString($value), true) : null;
        } catch (Throwable) {
            $data = null;
        }

        if (! is_array($data) || ! is_string($data['token'] ?? null) || $data['token'] === '') {
            $this->stored = false;

            return null;
        }

        $this->stored = [
            'workspace' => is_string($data['workspace'] ?? null) ? $data['workspace'] : null,
            'host' => is_string($data['host'] ?? null) ? $data['host'] : null,
            'connected_at' => is_string($data['connected_at'] ?? null) ? $data['connected_at'] : null,
        ];

        return ['token' => $data['token']];
    }

    /**
     * Keep a key made in the control panel. Throws when the table is
     * missing, so a connection is never reported as done when it isn't.
     */
    public function store(#[SensitiveParameter] SiteKey $key, ?string $workspace = null, ?string $host = null): void
    {
        $value = self::seal((string) json_encode([
            'token' => $key->token(),
            'workspace' => $workspace,
            'host' => $host,
            'connected_at' => Carbon::now()->toIso8601String(),
        ]));

        DB::table(self::TABLE)->updateOrInsert(['key' => self::ROW], ['value' => $value, 'updated_at' => Carbon::now(), 'created_at' => Carbon::now()]);
        DB::table(self::TABLE)->where('key', self::TURNED_AWAY)->delete();

        $this->stored = null;
    }

    /**
     * Porro no longer accepts the stored key: the site was removed there,
     * or given a new key. The key is no use to anyone, so it's deleted,
     * and who it was for and when is kept so the panel can say what
     * happened. Does nothing for a key from the environment.
     */
    public function turnAway(): void
    {
        $stored = $this->stored();

        if ($stored === null || $this->fromEnv() !== null) {
            return;
        }

        try {
            DB::table(self::TABLE)->updateOrInsert(['key' => self::TURNED_AWAY], [
                'value' => (string) json_encode(['workspace' => $stored['workspace'], 'at' => Carbon::now()->toIso8601String()]),
                'updated_at' => Carbon::now(),
                'created_at' => Carbon::now(),
            ]);
        } catch (Throwable) {
            // The note is a nicety; the key still goes.
        }

        $this->forget();
    }

    /**
     * Set when Porro turned the stored key away, until the site connects
     * again. Nothing secret in it.
     *
     * @return array{workspace: string|null, at: string|null}|null
     */
    public function turnedAway(): ?array
    {
        try {
            $data = json_decode((string) DB::table(self::TABLE)->where('key', self::TURNED_AWAY)->value('value'), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($data) ? [
            'workspace' => is_string($data['workspace'] ?? null) ? $data['workspace'] : null,
            'at' => is_string($data['at'] ?? null) ? $data['at'] : null,
        ] : null;
    }

    /**
     * Encrypt something that holds a key. If encrypting fails, the
     * framework's exception carries the plain text in its trace (the
     * Crypt facade's arguments, and the encrypter's own on Laravel 10), so
     * it's swapped for one that doesn't.
     */
    public static function seal(#[SensitiveParameter] string $plain): string
    {
        try {
            return app('encrypter')->encryptString($plain);
        } catch (Throwable) {
            throw new RuntimeException('Couldn’t encrypt the Porro site key. Check the app key and cipher.');
        }
    }

    public function forget(): void
    {
        try {
            DB::table(self::TABLE)->where('key', self::ROW)->delete();
        } catch (Throwable) {
            // No table, nothing kept.
        }

        $this->stored = null;
    }

    /**
     * Whether a key could be kept right now: the table is there and can
     * be written to. Checked with a real write, just before asking Porro
     * to make the key the site's.
     */
    public function ready(): bool
    {
        try {
            DB::table(self::TABLE)->updateOrInsert(['key' => self::PROBE], ['value' => '', 'updated_at' => Carbon::now(), 'created_at' => Carbon::now()]);
            DB::table(self::TABLE)->where('key', self::PROBE)->delete();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
