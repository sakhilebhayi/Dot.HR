<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workforce time and competency (R-32 Phase 6a): shifts and who works them, attendance
 * (clock in and out — no location or biometric data is collected), weekly timesheets with
 * approval, and certifications with the requirements a position carries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->string('title', 120)->nullable();
            $table->string('location', 120)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['team_id', 'starts_at']);
        });

        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['shift_id', 'employee_id']);
            $table->index(['team_id', 'employee_id']);
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('clock_in_at');
            $table->dateTime('clock_out_at')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);
            // self (the person clocked) | supervisor (recorded or corrected by someone else)
            $table->string('source', 12)->default('self');
            $table->string('note', 255)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['team_id', 'clock_in_at']);
            $table->index(['employee_id', 'clock_in_at']);
        });

        Schema::create('timesheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('week_start');
            // draft | submitted | approved | rejected
            $table->string('status', 12)->default('draft');
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'week_start']);
            $table->index(['team_id', 'status']);
        });

        Schema::create('employee_certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('name', 140);
            $table->string('issuer', 140)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            // When the expiry warning went out, so it goes out once.
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'expires_on']);
        });

        // What a position requires (a licence, a medical, an induction) — the basis of the
        // readiness view. Matched to certifications by name, case-insensitively.
        Schema::create('position_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('position_id')->constrained('positions')->cascadeOnDelete();
            $table->string('name', 140);
            $table->timestamps();
            $table->unique(['position_id', 'name']);
        });

        Schema::table('teams', function (Blueprint $table) {
            // Ordinary hours a week, above which time is flagged as overtime. A business sets
            // it to what its contracts and jurisdiction say; empty means nothing is flagged.
            $table->unsignedSmallInteger('weekly_ordinary_hours')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('weekly_ordinary_hours');
        });
        Schema::dropIfExists('position_requirements');
        Schema::dropIfExists('employee_certifications');
        Schema::dropIfExists('timesheets');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('shift_assignments');
        Schema::dropIfExists('shifts');
    }
};
