<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learning paths (brief §10: learning paths, structured development). A path is an ordered set
 * of steps a person works through: a course the business books, a session someone runs in
 * house, something to read, something to practise. Progress is the steps done, shown to the
 * person and HR; it is never a score and people are never ranked by it.
 *
 *   learning_paths             the business's paths, optionally for one position, and whether
 *                              people may start one themselves
 *   learning_path_steps        ordered steps; a course step can carry the certificate it gives
 *                              and the competency it grows; retired steps keep their history
 *   learning_path_enrolments   one person on one path: who put them on it, when, done or dropped
 *   learning_path_progress     one step for one person: done (and who confirmed it), or a course
 *                              asked for or booked, linked to its training record
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_paths', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->boolean('self_enrol')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'retired_at']);
        });

        Schema::create('learning_path_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('learning_path_id')->constrained('learning_paths')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('title', 160);
            $table->string('kind', 12); // course | session | reading | practice
            $table->string('detail', 500)->nullable();
            $table->string('provider', 140)->nullable();
            $table->string('certification_name', 140)->nullable();
            $table->foreignId('competency_id')->nullable()->constrained('competencies')->nullOnDelete();
            $table->unsignedSmallInteger('within_days')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->index(['learning_path_id', 'position']);
        });

        Schema::create('learning_path_enrolments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('learning_path_id')->constrained('learning_paths')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('started_on');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('dropped_at')->nullable();
            $table->timestamps();
            $table->unique(['learning_path_id', 'employee_id']);
            $table->index(['team_id', 'employee_id']);
        });

        Schema::create('learning_path_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('learning_path_enrolment_id')->constrained('learning_path_enrolments')->cascadeOnDelete();
            $table->foreignId('learning_path_step_id')->constrained('learning_path_steps')->cascadeOnDelete();
            $table->foreignId('training_record_id')->nullable()->constrained('training_records')->nullOnDelete();
            $table->date('done_on')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['learning_path_enrolment_id', 'learning_path_step_id'], 'lpp_enrolment_step_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_path_progress');
        Schema::dropIfExists('learning_path_enrolments');
        Schema::dropIfExists('learning_path_steps');
        Schema::dropIfExists('learning_paths');
    }
};
