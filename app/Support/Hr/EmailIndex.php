<?php

namespace App\Support\Hr;

/**
 * Blind index for employee email. The address itself is stored encrypted, so the
 * database cannot enforce uniqueness or answer "who has this address?" on it. This
 * keyed hash of the lower-cased address can: (team_id, email_hash) is unique, and an
 * exact-match search or the sign-in link compares hashes.
 *
 * The key is derived from APP_KEY. Rotating APP_KEY therefore means re-running
 * `php artisan hr:protect-data --reindex` after re-encrypting.
 */
final class EmailIndex
{
    public static function normalise(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    public static function hash(?string $email): ?string
    {
        $email = self::normalise($email);

        if ($email === null) {
            return null;
        }

        return hash_hmac('sha256', $email, hash('sha256', 'dot-hr:email-index|'.config('app.key')));
    }
}
