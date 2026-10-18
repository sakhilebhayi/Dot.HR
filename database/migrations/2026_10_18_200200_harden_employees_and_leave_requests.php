<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Soft deletes, the email blind index, room for ciphertext, and the leave-request
 * columns the new rules need. Data is kept: nothing is dropped, and the stored
 * `on_leave` flag is retired (on leave is now computed from approved leave).
 *
 * Unique indexes come in the next migration, after existing rows are encrypted and
 * indexed, so a legacy duplicate cannot make this one fail halfway.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Encrypted values are longer than the old varchar(255) / varchar(50). SQLite has
        // no length limit (and rebuilding `employees` there would cascade through
        // leave_requests), so only the real servers are altered.
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('employees', function (Blueprint $table) {
                $table->text('email')->nullable()->change();
                $table->text('phone')->nullable()->change();
            });
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->string('email_hash', 64)->nullable()->after('email');
            $table->softDeletes();
            $table->index(['team_id', 'user_id']);
        });

        // enum() is a CHECK constraint on PostgreSQL that change() does not drop.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT IF EXISTS leave_requests_type_check');
            DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT IF EXISTS leave_requests_status_check');
        }

        // type becomes a snapshot of the leave type's slug (any team-defined slug);
        // status gains `cancelled`. leave_requests has no child tables, so SQLite's
        // rebuild-on-change is safe here.
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('type', 60)->default('annual')->change();
            $table->string('status', 20)->default('pending')->change();
        });

        // Now that employees can file their own requests, deleting an account (Jetstream account
        // deletion is on) must not be blocked by the requests it filed: keep the request, forget who.
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropForeign(['requested_by']);
        });
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('requested_by')->nullable()->change();
        });
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreignId('leave_type_id')->nullable()->after('employee_id')->constrained('leave_types')->nullOnDelete();
            $table->string('half_day', 2)->nullable()->after('end_date');
            $table->softDeletes();
            $table->index(['employee_id', 'start_date']);
        });

        DB::statement(
            'UPDATE leave_requests SET leave_type_id = (SELECT lt.id FROM leave_types lt WHERE lt.team_id = leave_requests.team_id AND lt.slug = leave_requests.type) WHERE leave_type_id IS NULL'
        );

        // A stored on_leave flag goes stale the moment the leave ends; it is computed now.
        DB::table('employees')->where('status', 'on_leave')->update(['status' => 'active']);
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'start_date']);
            $table->dropConstrainedForeignId('leave_type_id');
            $table->dropColumn(['half_day', 'deleted_at']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'user_id']);
            $table->dropColumn(['email_hash', 'deleted_at']);
        });
    }
};
