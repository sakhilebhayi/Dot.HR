<?php

use App\Support\Hr\SensitiveDataProtector;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Encrypts existing employee email/phone and leave reasons in place, fills the email
 * blind index, then adds the uniqueness the encrypted columns cannot enforce:
 *   - one active employee per (team, email_hash)
 *   - one active employee per (team, linked user)
 * Both are partial indexes (active rows only) so a soft-deleted record never blocks a
 * new one; partial unique indexes exist on PostgreSQL and SQLite. Other engines keep
 * the application-level checks only.
 *
 * Idempotent: re-running changes nothing (see SensitiveDataProtector).
 */
return new class extends Migration
{
    public function up(): void
    {
        (new SensitiveDataProtector)->run();

        // The same account cannot be linked to two employees of one team. Keep the oldest.
        $dupes = DB::table('employees')
            ->select('team_id', 'user_id', DB::raw('MIN(id) as keep_id'))
            ->whereNotNull('user_id')->whereNull('deleted_at')
            ->groupBy('team_id', 'user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dupes as $dupe) {
            DB::table('employees')
                ->where('team_id', $dupe->team_id)->where('user_id', $dupe->user_id)
                ->whereNull('deleted_at')->where('id', '!=', $dupe->keep_id)
                ->update(['user_id' => null]);
        }

        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement('CREATE UNIQUE INDEX employees_team_email_hash_unique ON employees (team_id, email_hash) WHERE email_hash IS NOT NULL AND deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX employees_team_user_unique ON employees (team_id, user_id) WHERE user_id IS NOT NULL AND deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS employees_team_email_hash_unique');
        DB::statement('DROP INDEX IF EXISTS employees_team_user_unique');
    }
};
