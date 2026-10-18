<?php

namespace App\Support\Hr;

use App\Casts\SensitiveText;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Brings rows written before encryption existed up to date: encrypts the employee
 * email and phone and the leave reason, and fills the email blind index. Works on raw
 * rows (no models, so no scopes, events or audit noise) and is idempotent: a value
 * that is already an encrypted payload is left alone, and a second run writes nothing.
 *
 * Where two active employees in one team share an address, both keep their data but
 * only the first (lowest id) is indexed; the rest get a null email_hash and are
 * reported in `duplicate_emails` so an admin can fix them. They cannot be linked by
 * email until then.
 *
 * With $reindex the hashes are recomputed even for already-encrypted rows (use after
 * rotating APP_KEY and re-encrypting).
 */
class SensitiveDataProtector
{
    /**
     * @return array{employees: int, leave_requests: int, duplicate_emails: int}
     */
    public function run(bool $reindex = false): array
    {
        return $this->protect($reindex);
    }

    /**
     * @return array{employees: int, leave_requests: int, duplicate_emails: int}
     */
    private function protect(bool $reindex): array
    {
        $changedEmployees = 0;
        $duplicates = 0;

        /** @var array<string, int> hash key (team|hash) => id of the active row that owns it */
        $claimed = [];

        DB::table('employees')->orderBy('id')->chunkById(200, function ($rows) use (&$changedEmployees, &$duplicates, &$claimed, $reindex): void {
            foreach ($rows as $row) {
                $update = [];

                foreach (['email', 'phone'] as $column) {
                    $value = $row->{$column};

                    if ($value !== null && ! SensitiveText::looksEncrypted((string) $value)) {
                        $update[$column] = Crypt::encryptString((string) $value);
                    }
                }

                $plainEmail = $row->email === null
                    ? null
                    : (SensitiveText::looksEncrypted((string) $row->email) ? Crypt::decryptString((string) $row->email) : (string) $row->email);

                $hash = EmailIndex::hash($plainEmail);
                $active = $row->deleted_at === null;

                if ($hash !== null && $active) {
                    $key = $row->team_id.'|'.$hash;

                    if (isset($claimed[$key]) && $claimed[$key] !== $row->id) {
                        $hash = null;
                        $duplicates++;
                    } else {
                        $claimed[$key] = $row->id;
                    }
                }

                $needsHash = $reindex || $row->email_hash !== $hash;

                if ($needsHash && ($hash !== null || $row->email_hash !== null)) {
                    $update['email_hash'] = $hash;
                }

                if ($update !== []) {
                    DB::table('employees')->where('id', $row->id)->update($update);
                    $changedEmployees++;
                }
            }
        });

        $changedLeave = 0;

        DB::table('leave_requests')->whereNotNull('reason')->orderBy('id')->chunkById(200, function ($rows) use (&$changedLeave): void {
            foreach ($rows as $row) {
                if (! SensitiveText::looksEncrypted((string) $row->reason)) {
                    DB::table('leave_requests')->where('id', $row->id)->update(['reason' => Crypt::encryptString((string) $row->reason)]);
                    $changedLeave++;
                }
            }
        });

        return ['employees' => $changedEmployees, 'leave_requests' => $changedLeave, 'duplicate_emails' => $duplicates];
    }
}
