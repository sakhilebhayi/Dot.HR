<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contractors and development (R-32 Phase 6b): engagements with milestones and invoice
 * records for external workers; written reviews (no scores) with goals; training records.
 */
return new class extends Migration
{
    public function up(): void
    {
        // An engagement sits on a person record whose employment type is "contractor". The
        // rate lives here, not on the employee record, and is shown to HR administrators and
        // the contractor only.
        Schema::create('engagements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('title', 160);
            $table->text('scope')->nullable();
            $table->string('rate_basis', 12);
            $table->decimal('rate_amount', 12, 2)->nullable();
            $table->char('currency', 3)->default('ZAR');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            // active | ended
            $table->string('status', 12)->default('active');
            $table->unsignedTinyInteger('renewals')->default(0);
            $table->string('end_reason', 24)->nullable();
            $table->date('ended_on')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['team_id', 'status']);
        });

        Schema::create('engagement_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->date('due_on')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            // open | submitted | approved | rejected
            $table->string('status', 12)->default('open');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->timestamps();
        });

        // A record of an invoice the contractor sent. Dot.HR moves no money: "paid" is what
        // the business records after paying it elsewhere.
        Schema::create('contractor_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 60);
            $table->decimal('amount', 12, 2);
            $table->date('received_on');
            // received | approved | disputed | paid
            $table->string('status', 12)->default('received');
            $table->string('note', 255)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['engagement_id', 'reference']);
        });

        Schema::create('review_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 120);
            $table->date('closes_on')->nullable();
            // open | closed
            $table->string('status', 12)->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // A written review: the person's own reflection, the reviewer's feedback and the
        // person's response — all encrypted at rest. There is no score column, by design.
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('review_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            // self | reviewer | shared | acknowledged
            $table->string('status', 14)->default('self');
            $table->text('self_assessment')->nullable();
            $table->boolean('self_skipped')->default(false);
            $table->text('feedback')->nullable();
            $table->text('response')->nullable();
            $table->timestamp('self_done_at')->nullable();
            $table->timestamp('shared_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->unique(['review_cycle_id', 'employee_id']);
            $table->index(['team_id', 'reviewer_id']);
        });

        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            // performance | development
            $table->string('kind', 12)->default('performance');
            $table->string('title', 160);
            $table->text('detail')->nullable();
            $table->date('due_on')->nullable();
            // open | on_track | at_risk | done | dropped
            $table->string('status', 12)->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['team_id', 'employee_id']);
        });

        Schema::create('training_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('provider', 140)->nullable();
            // internal | external
            $table->string('kind', 10)->default('external');
            // requested | planned | completed | declined
            $table->string('status', 12);
            $table->date('starts_on')->nullable();
            $table->date('completed_on')->nullable();
            $table->decimal('hours', 5, 1)->nullable();
            // On completion this becomes a certification record, which feeds readiness.
            $table->string('certification_name', 140)->nullable();
            $table->date('certification_expires_on')->nullable();
            $table->string('reason', 255)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->timestamps();
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_records');
        Schema::dropIfExists('goals');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('review_cycles');
        Schema::dropIfExists('contractor_invoices');
        Schema::dropIfExists('engagement_milestones');
        Schema::dropIfExists('engagements');
    }
};
