<?php

namespace App\Console\Commands;

use App\Casts\SensitiveText;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The restore drill's last step (R-32 Phase 8): proves a restored copy of the Dot.HR database
 * is usable by THIS code and THIS APP_KEY. A backup that restores but whose encrypted columns
 * cannot be read is not a backup, so the decrypt check is the point.
 *
 *   php artisan hr:verify-restore                       # the app's own database
 *   php artisan hr:verify-restore --connection=restore  # a scratch copy configured as "restore"
 *
 * Prints table row counts (totals only), pending migrations, and how many sampled encrypted
 * values decrypt with the current key. Exits non-zero when anything would stop Dot.HR working.
 * It reads only; it never writes to the database it checks.
 */
class VerifyRestore extends Command
{
    protected $signature = 'hr:verify-restore {--connection= : Database connection holding the restored copy} {--sample=25 : Encrypted values to test per column}';

    protected $description = 'Check a restored Dot.HR database: schema, row counts, and that encrypted columns decrypt with this APP_KEY';

    /** Columns written through the SensitiveText cast. */
    private const ENCRYPTED = [
        'employees' => ['email', 'phone', 'emergency_contact_name', 'emergency_contact_phone'],
        'leave_requests' => ['reason'],
        'hr_requests' => ['body', 'response'],
        'reviews' => ['self_assessment', 'feedback', 'response'],
    ];

    private const COUNTED = ['teams', 'users', 'employees', 'leave_requests', 'vacancies', 'applications', 'attendance_records', 'reviews', 'hr_audit_log'];

    public function handle(Migrator $migrator): int
    {
        $connection = $this->option('connection') ?: config('database.default');
        $db = DB::connection($connection);
        $failed = false;

        try {
            $db->getPdo();
        } catch (Throwable) {
            $this->error("Cannot connect to the \"{$connection}\" database.");

            return self::FAILURE;
        }

        $this->info("Checking the \"{$connection}\" database.");

        // Schema: every migration this code ships must have run on the copy.
        $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));
        $ran = $db->table('migrations')->pluck('migration')->all();
        $pending = array_values(array_diff($files, $ran));
        if ($pending !== []) {
            $failed = true;
            $this->error(count($pending).' migration(s) not applied: '.implode(', ', $pending));
        } else {
            $this->line('Schema: all '.count($files).' migrations applied.');
        }

        // Row counts, totals only — enough to spot an empty or truncated restore.
        $rows = [];
        foreach (self::COUNTED as $table) {
            $rows[] = [$table, Schema::connection($connection)->hasTable($table) ? $db->table($table)->count() : 'missing'];
        }
        $this->table(['Table', 'Rows'], $rows);

        // Encryption: sample real ciphertexts and decrypt them with this key.
        $tested = 0;
        $broken = 0;
        foreach (self::ENCRYPTED as $table => $columns) {
            if (! Schema::connection($connection)->hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                $values = $db->table($table)->whereNotNull($column)->limit((int) $this->option('sample'))->pluck($column);
                foreach ($values as $value) {
                    if (! SensitiveText::looksEncrypted((string) $value)) {
                        continue;
                    }
                    $tested++;
                    try {
                        Crypt::decryptString((string) $value);
                    } catch (Throwable) {
                        $broken++;
                    }
                }
            }
        }

        if ($broken > 0) {
            $failed = true;
            $this->error("{$broken} of {$tested} sampled encrypted values do NOT decrypt with this APP_KEY. The copy and the key do not belong together: find the APP_KEY that was live when the backup was taken.");
        } else {
            $this->line($tested === 0 ? 'Encryption: no encrypted values to test (an empty business?).' : "Encryption: {$tested} sampled values decrypt with this APP_KEY.");
        }

        $failed ? $this->error('Restore check FAILED.') : $this->info('Restore check passed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
