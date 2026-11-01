<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Competency frameworks and 360 feedback (R-32 brief §10: competency assessments, competency
 * matrices, skills gaps, continuous feedback). In keeping with "without ranking anyone", nothing
 * here is a score: a level is one of three written descriptions of what the work looks like
 * (learning, practising, leading), never added up, averaged or compared between people.
 *
 *   competencies             the business's framework, by area, each with three level descriptions
 *   position_competencies    the matrix: which positions need which competency, at what level
 *   competency_assessments   one person on one competency: where they place themselves (with
 *                            evidence) and the level HR agreed, with a note
 *   feedback_rounds          a 360 round about one person, with a due date
 *   feedback_responses       one colleague's written answer; the person reads the words without
 *                            names, and only once enough people answered that nobody is singled out
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('area', 80)->nullable();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->text('level_1');
            $table->text('level_2');
            $table->text('level_3');
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'area']);
        });

        Schema::create('position_competencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('position_id')->constrained('positions')->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained('competencies')->cascadeOnDelete();
            $table->unsignedTinyInteger('expected_level');
            $table->timestamps();
            $table->unique(['position_id', 'competency_id']);
        });

        Schema::create('competency_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained('competencies')->cascadeOnDelete();
            $table->unsignedTinyInteger('self_level')->nullable();
            $table->text('self_evidence')->nullable(); // encrypted
            $table->timestamp('self_at')->nullable();
            $table->unsignedTinyInteger('agreed_level')->nullable();
            $table->text('agreed_note')->nullable(); // encrypted
            $table->foreignId('agreed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('agreed_at')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'competency_id']);
        });

        Schema::create('feedback_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('focus', 500)->nullable();
            $table->date('due_on');
            $table->string('status', 10)->default('open'); // open | shared | closed
            $table->timestamp('shared_at')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'status']);
        });

        Schema::create('feedback_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('feedback_round_id')->constrained('feedback_rounds')->cascadeOnDelete();
            $table->foreignId('giver_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('keep_doing')->nullable(); // encrypted
            $table->text('do_differently')->nullable(); // encrypted
            $table->text('anything_else')->nullable(); // encrypted
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamps();
            $table->unique(['feedback_round_id', 'giver_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_responses');
        Schema::dropIfExists('feedback_rounds');
        Schema::dropIfExists('competency_assessments');
        Schema::dropIfExists('position_competencies');
        Schema::dropIfExists('competencies');
    }
};
