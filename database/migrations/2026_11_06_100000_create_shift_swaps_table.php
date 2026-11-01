<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shift swaps (R-32 brief §8): a person hands their shift to a colleague, or exchanges it for
 * one of the colleague's, the colleague accepts, and a supervisor approves. Only then does the
 * schedule change, through the same rules as assigning by hand (no overlapping shifts, nobody
 * on approved leave). An offer with no named colleague is open to anyone in the business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_swaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->foreignId('from_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('to_employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->foreignId('other_shift_id')->nullable()->constrained('shifts')->cascadeOnDelete();
            $table->string('note', 255)->nullable();
            $table->string('status', 12)->default('offered'); // offered | accepted | approved | declined | cancelled | expired
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_note', 255)->nullable();
            $table->timestamps();
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_swaps');
    }
};
