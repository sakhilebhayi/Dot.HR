<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The recruitment marketplace (R-32 Phase 4): roles a business publishes, the people who
 * apply, everything the business does with an application, and the offer that turns a
 * candidate into an employee. Every employer-side table carries team_id so the team scope
 * keeps one business's candidates, notes and offers invisible to every other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 140);
            $table->text('description');
            $table->text('responsibilities')->nullable();
            $table->text('requirements')->nullable();
            $table->json('skills')->nullable();
            // Keys of App\Models\ProfessionalProfile::WORK_TYPES / ARRANGEMENTS.
            $table->string('work_type', 24);
            $table->string('arrangement', 16);
            $table->string('location', 120)->nullable();
            // salary | hourly | daily | project | commission | unpaid | undisclosed
            $table->string('pay_basis', 16)->default('undisclosed');
            $table->decimal('pay_min', 12, 2)->nullable();
            $table->decimal('pay_max', 12, 2)->nullable();
            $table->string('currency', 3)->default('ZAR');
            $table->string('hours', 120)->nullable();
            $table->unsignedSmallInteger('openings')->default(1);
            $table->date('starts_on')->nullable();
            $table->date('closes_on')->nullable();
            $table->json('screening_questions')->nullable();
            // draft | published | paused | closed
            $table->string('status', 12)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['status', 'closes_on']);
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('vacancy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // new | review | shortlisted | interview | offer | hired | rejected | withdrawn
            $table->string('stage', 16)->default('new');
            $table->text('cover_note')->nullable();
            $table->json('answers')->nullable();
            $table->timestamp('stage_changed_at')->nullable();
            $table->timestamps();

            // One person, one application per role: a retried or double-clicked apply
            // can never create a second.
            $table->unique(['vacancy_id', 'user_id']);
            $table->index(['team_id', 'stage']);
            $table->index('user_id');
        });

        // Private to the employer: notes and the history are never shown to the candidate.
        Schema::create('application_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('application_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 32);
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['application_id', 'created_at']);
        });

        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('starts_at');
            $table->unsignedSmallInteger('duration_minutes')->default(45);
            // in_person | phone | video
            $table->string('mode', 12);
            // An address, a phone line or a meeting link — shown to the candidate.
            $table->string('where', 255)->nullable();
            $table->text('candidate_note')->nullable();
            // scheduled | completed | cancelled
            $table->string('status', 12)->default('scheduled');
            // Employer-only: the panel's verdict and notes.
            $table->string('recommendation', 12)->nullable();
            $table->text('feedback')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'starts_at']);
        });

        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('job_title', 140);
            $table->string('pay_basis', 16);
            $table->decimal('pay_amount', 12, 2)->nullable();
            $table->string('currency', 3)->default('ZAR');
            $table->date('starts_on');
            $table->text('terms')->nullable();
            // draft | sent | accepted | declined | withdrawn
            $table->string('status', 12)->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            // Set exactly once when the accepted offer becomes an employee record.
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
        Schema::dropIfExists('interviews');
        Schema::dropIfExists('application_events');
        Schema::dropIfExists('application_notes');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('vacancies');
    }
};
