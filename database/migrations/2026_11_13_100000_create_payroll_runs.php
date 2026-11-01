<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll sign-offs (brief §11: payroll preparation, approval, exports). Dot.HR holds no pay and
 * calculates nothing; HR signs off the inputs for a period (starters, leavers, approved hours,
 * leave) before they go to the payroll provider. A fingerprint of the inputs is kept, so the page
 * can say when something changed after the sign-off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('people');
            $table->char('inputs_hash', 64);
            $table->string('note', 255)->nullable();
            $table->foreignId('signed_off_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('signed_off_at');
            $table->timestamps();
            $table->index(['team_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
