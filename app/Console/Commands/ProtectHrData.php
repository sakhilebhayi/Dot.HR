<?php

namespace App\Console\Commands;

use App\Support\Hr\SensitiveDataProtector;
use Illuminate\Console\Command;

/**
 * Encrypts any employee email/phone and leave reason still stored as plaintext, and fills
 * the email blind index. Idempotent; the migration already runs it once. Use --reindex
 * after rotating APP_KEY and re-encrypting, to recompute every email hash.
 */
class ProtectHrData extends Command
{
    protected $signature = 'hr:protect-data {--reindex : Recompute every email blind index, not just missing ones}';

    protected $description = 'Encrypt legacy plaintext HR fields and fill the email blind index (safe to re-run)';

    public function handle(SensitiveDataProtector $protector): int
    {
        $result = $protector->run((bool) $this->option('reindex'));

        $this->info(sprintf(
            'Updated %d employee row(s) and %d leave request(s).',
            $result['employees'],
            $result['leave_requests'],
        ));

        if ($result['duplicate_emails'] > 0) {
            $this->warn(sprintf(
                '%d employee(s) share an email address with another active employee in their team. Their data is kept, but they are not indexed (and cannot be linked by email) until the duplicate is fixed.',
                $result['duplicate_emails'],
            ));
        }

        return self::SUCCESS;
    }
}
