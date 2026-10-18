<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-team leave types (entitlement, carry-over, whether the balance is enforced).
 * Existing teams get the default set so the next migration can attach their old
 * leave requests to a type.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_types')) {
            Schema::create('leave_types', function (Blueprint $table) {
                $table->id();
                $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                $table->string('name', 100);
                $table->string('slug', 60);
                // null = not tracked against a balance (sick, unpaid ...).
                $table->decimal('annual_days', 5, 1)->nullable();
                // The most that can roll into the next leave year.
                $table->decimal('carry_over_days', 5, 1)->default(0);
                $table->boolean('is_paid')->default(true);
                // true = a request that would exceed the balance is refused.
                $table->boolean('enforce_balance')->default(false);
                $table->timestamps();

                $table->unique(['team_id', 'slug']);
            });
        }

        $now = now();

        foreach (DB::table('teams')->pluck('id') as $teamId) {
            foreach (config('hr.default_leave_types', []) as $type) {
                $exists = DB::table('leave_types')->where('team_id', $teamId)->where('slug', $type['slug'])->exists();

                if (! $exists) {
                    DB::table('leave_types')->insert($type + ['team_id' => $teamId, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
