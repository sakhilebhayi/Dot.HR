<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leaving well (R-32 brief §15: resignation workflows, exit interviews, final documentation).
 * The offboarding checklist (Phase 5) already carries asset returns, access removal and final
 * pay; these tables add what it could not:
 *
 *   resignations          notice given in writing from My work (BCEA s37(4) asks for written
 *                         notice), with the minimum notice period worked out, HR's receipt, the
 *                         agreed last day, and a withdrawal only by agreement.
 *   exit_interviews       voluntary and confidential: answers encrypted, read by HR
 *                         administrators only, and counted into anonymous patterns only when
 *                         the person agreed and there are enough answers to hide anyone.
 *   service_certificates  the certificate of service BCEA s42 requires on termination, with
 *                         the reason only when the person asked for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resignations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('given_at');
            $table->date('last_day');
            $table->unsignedSmallInteger('minimum_notice_days');
            $table->string('reason', 20)->nullable();
            $table->text('message')->nullable(); // encrypted
            $table->string('status', 20)->default('given'); // given | acknowledged | withdrawn
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('agreed_last_day')->nullable();
            $table->timestamp('withdrawal_requested_at')->nullable();
            $table->text('withdrawal_note')->nullable(); // encrypted
            $table->timestamp('withdrawal_decided_at')->nullable();
            $table->foreignId('lifecycle_checklist_id')->nullable()->constrained('lifecycle_checklists')->nullOnDelete();
            $table->timestamps();
            $table->index(['team_id', 'status']);
        });

        Schema::create('exit_interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamp('offered_at');
            $table->foreignId('offered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mode', 10)->nullable(); // form | meeting
            $table->json('reasons')->nullable(); // category keys only
            $table->text('answers')->nullable(); // encrypted JSON
            $table->string('would_return', 10)->nullable(); // yes | no | unsure
            $table->boolean('share_anonymously')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'completed_at']);
        });

        Schema::create('service_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('employer_name', 160);
            $table->string('employer_address', 255);
            $table->string('job_title', 160);
            $table->date('start_date');
            $table->date('end_date');
            $table->text('remuneration'); // encrypted: pay at the date of termination
            $table->string('reason', 255)->nullable(); // only when the person asked for it
            $table->timestamp('issued_at');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_certificates');
        Schema::dropIfExists('exit_interviews');
        Schema::dropIfExists('resignations');
    }
};
