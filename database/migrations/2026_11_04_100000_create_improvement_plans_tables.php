<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance improvement plans (R-32 brief §10 and §15). South Africa's Code of Good Practice:
 * Dismissal (LRA Schedule 8, items 8 and 9) treats poor work performance as incapacity: the
 * employee is told what standard is not met, given guidance, training or counselling, and a
 * reasonable time to improve. A plan records exactly that: the concern, the standard, the
 * support, the period, objectives with how each is measured, regular check-ins the person can
 * answer, and the outcome. There is no score or rating of the person, in keeping with Reviews.
 *
 * The concern, standard, support, check-in notes and the person's comments are encrypted at
 * rest. A plan is seen by HR administrators, the manager supporting it, and the person — never
 * by colleagues or other managers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('improvement_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('hr_case_id')->nullable()->constrained('hr_cases')->nullOnDelete();
            $table->string('title', 160);
            $table->text('concern'); // encrypted: what is below standard, with examples
            $table->text('standard'); // encrypted: what meeting the standard looks like
            $table->text('support'); // encrypted: the guidance, training or coaching offered
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedSmallInteger('check_in_days')->default(14);
            $table->string('status', 20)->default('draft'); // draft | active | completed | cancelled
            $table->timestamp('shared_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('employee_response')->nullable(); // encrypted
            $table->unsignedTinyInteger('extensions')->default(0);
            $table->string('outcome', 20)->nullable(); // met | not_met
            $table->text('outcome_notes')->nullable(); // encrypted
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'employee_id']);
        });

        Schema::create('improvement_plan_objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('improvement_plan_id')->constrained('improvement_plans')->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('measure', 255); // how anyone can tell it is met
            $table->date('due_on')->nullable();
            $table->string('status', 10)->default('open'); // open | met | not_met
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('improvement_plan_check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('improvement_plan_id')->constrained('improvement_plans')->cascadeOnDelete();
            $table->date('held_on');
            $table->text('notes'); // encrypted, shared with the person
            $table->text('employee_comment')->nullable(); // encrypted
            $table->timestamp('commented_at')->nullable();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['improvement_plan_id', 'held_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('improvement_plan_check_ins');
        Schema::dropIfExists('improvement_plan_objectives');
        Schema::dropIfExists('improvement_plans');
    }
};
