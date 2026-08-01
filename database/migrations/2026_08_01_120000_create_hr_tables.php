<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core HR MVP schema: positions, employees, leave requests.
 *
 * PII posture (see wiki.md §2 "Work, Not Workers" and Dot.Brain's
 * platforms/dot-hr.md §7 field-classification register): every table here
 * is team-scoped (`team_id`) and intentionally excludes the `prohibited`-tier
 * fields named in that register — national ID numbers, salary, health/
 * disability data, disciplinary history. Only name, contact, role, and
 * employment-status fields are stored. If salary or ID-number fields are
 * ever added, they need real encryption-at-rest and field-level access
 * control review first, per Dot.Brain's os/17-Security.md — this migration
 * deliberately does not include them yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('title');
            $table->string('department')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'title']);
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();

            // Optional link to a platform login (Jetstream User). Nullable because
            // not every employee record needs — or should have — ecosystem login
            // access (e.g. contractors managed only for roster purposes).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Minimal PII surface by design: name and work contact only.
            // No national ID / passport number, no salary, no medical data.
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            $table->enum('employment_type', ['full_time', 'part_time', 'contractor'])->default('full_time');
            $table->enum('status', ['active', 'on_leave', 'terminated'])->default('active');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            // Who added this record, for audit purposes (not the employee's own account).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['team_id', 'status']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->enum('type', ['annual', 'sick', 'unpaid', 'other'])->default('annual');
            $table->date('start_date');
            $table->date('end_date');

            // Free-text reason. Note: for `sick` leave this field can incidentally
            // contain health information typed in by the requester even though no
            // structured medical field exists on this table. Treat as sensitive;
            // this is exactly the kind of field the aggregation-layer / field-tier
            // work in wiki.md §9 and platforms/dot-hr.md §7 still needs to cover
            // before any leave data could ever be aggregated or exported.
            $table->text('reason')->nullable();

            $table->enum('status', ['pending', 'approved', 'denied'])->default('pending');

            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('positions');
    }
};
