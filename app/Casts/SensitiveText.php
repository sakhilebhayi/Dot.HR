<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypts a text column at rest (Laravel's encrypter, AES-256-CBC with a MAC, keyed by APP_KEY).
 *
 * Unlike the built-in `encrypted` cast it tolerates a legacy plaintext value: rows
 * written before this cast existed stay readable during the window between deploying
 * the code and running `php artisan hr:protect-data` (the migration does it for you).
 * A value that *is* an encrypted payload but fails its MAC is never passed through as
 * text: that is tampering or the wrong key, and it throws.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class SensitiveText implements CastsAttributes, ComparesCastableAttributes
{
    public function get($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::looksEncrypted((string) $value) ? Crypt::decryptString((string) $value) : (string) $value;
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString((string) $value);
    }

    /** Ciphertext differs on every write, so "did it change?" compares the plaintexts. */
    public function compare($model, string $key, $value, $other): bool
    {
        return $this->get($model, $key, $value, []) === $this->get($model, $key, $other, []);
    }

    /** True when $value has the shape of a Laravel encrypter payload (base64 JSON with iv, value, mac). */
    public static function looksEncrypted(string $value): bool
    {
        $json = base64_decode($value, true);

        if ($json === false || $json === '' || $json[0] !== '{') {
            return false;
        }

        $payload = json_decode($json, true);

        return is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac']);
    }
}
