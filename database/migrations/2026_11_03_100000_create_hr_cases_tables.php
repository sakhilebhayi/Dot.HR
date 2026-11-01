<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee relations cases (R-32 brief §15): grievances, disciplinary cases and workplace
 * incident records, with investigations, hearings, outcomes, warnings and appeals.
 *
 * Deliberately not ordinary employee notes: a case has its own confidential model. The
 * account of what happened, every timeline entry and every outcome reason are encrypted at
 * rest (SensitiveText). Entries are append-only and each one is either internal (HR and the
 * handler only) or shared with the person the case is about. Managers never see a case, and
 * the person a grievance is about is never shown it. A restricted case is open only to its
 * handler and the business owner (a grievance about an HR administrator, for example).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('type', 20); // grievance | disciplinary | incident
            $table->string('category', 30);
            // Who the case is about: the person who raised a grievance, the employee facing an
            // allegation, the person involved in an incident (optional for an incident).
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            // A grievance may be about a colleague; they are never shown the grievance.
            $table->foreignId('respondent_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('title', 160);
            $table->text('summary'); // encrypted
            $table->date('occurred_on')->nullable();
            $table->string('status', 20)->default('open'); // open | investigating | hearing | decided | appealed | closed | withdrawn
            $table->foreignId('handler_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('restricted')->default(false);
            $table->string('source', 10)->default('hr'); // hr | employee (a grievance lodged from My work)
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('hearing_at')->nullable();
            $table->string('hearing_place', 160)->nullable();
            $table->string('hearing_chair', 120)->nullable();

            $table->string('outcome', 30)->nullable();
            $table->text('outcome_reasons')->nullable(); // encrypted
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('warning_expires_on')->nullable();

            $table->timestamp('appealed_at')->nullable();
            $table->string('appeal_outcome', 20)->nullable(); // upheld | dismissed | varied
            $table->timestamp('appeal_decided_at')->nullable();

            // An incident that must be reported outside the business (a work injury to the
            // Compensation Fund): the date it was reported.
            $table->date('reported_externally_on')->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'employee_id']);
        });

        Schema::create('hr_case_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('hr_case_id')->constrained('hr_cases')->cascadeOnDelete();
            $table->string('kind', 20); // note | statement | notice | hearing | outcome | appeal | response | update
            $table->text('body'); // encrypted
            $table->boolean('shared')->default(false); // the person the case is about can read it
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('by_employee')->default(false);
            $table->timestamp('received_at')->nullable(); // the employee confirmed receiving a shared notice
            $table->timestamp('created_at');
            $table->index(['hr_case_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_case_entries');
        Schema::dropIfExists('hr_cases');
    }
};
