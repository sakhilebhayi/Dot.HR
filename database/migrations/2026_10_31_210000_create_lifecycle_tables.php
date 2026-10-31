<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The employee lifecycle (R-32 Phase 5): onboarding and offboarding checklists with tasks
 * owned by the person or by HR, a business's own default checklists, employee-raised HR
 * requests, and the emergency contact an employee keeps up to date themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A business's own default checklist per process; Dot.HR's defaults apply until
        // the business writes its own.
        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('process', 12);
            $table->json('items');
            $table->timestamps();
            $table->unique(['team_id', 'process']);
        });

        Schema::create('lifecycle_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            // onboarding | offboarding
            $table->string('process', 12);
            // open | done | cancelled
            $table->string('status', 12)->default('open');
            $table->date('due_on')->nullable();
            // Offboarding only: the last working day and a reason CATEGORY (never a narrative).
            $table->date('last_day')->nullable();
            $table->string('reason', 24)->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'status']);
            $table->index(['employee_id', 'process', 'status']);
        });

        Schema::create('lifecycle_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('lifecycle_checklist_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            // employee | hr
            $table->string('owner', 8);
            // task | acknowledge
            $table->string('kind', 12)->default('task');
            $table->text('body')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('hr_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 24);
            $table->string('subject', 160);
            // Encrypted at rest (App\Casts\SensitiveText): requests can carry personal matters.
            $table->text('body');
            $table->text('response')->nullable();
            // open | answered | closed
            $table->string('status', 12)->default('open');
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'status']);
            $table->index('employee_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            // Encrypted at rest like phone and email; the person maintains these themselves.
            $table->text('emergency_contact_name')->nullable();
            $table->text('emergency_contact_phone')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['emergency_contact_name', 'emergency_contact_phone']);
        });
        Schema::dropIfExists('hr_requests');
        Schema::dropIfExists('lifecycle_tasks');
        Schema::dropIfExists('lifecycle_checklists');
        Schema::dropIfExists('checklist_templates');
    }
};
