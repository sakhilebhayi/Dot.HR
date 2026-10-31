<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal talent (R-32 Phase 7): when a recruiter tells a current employee about an open
 * role they may suit. One invitation per person per role, so nobody is nagged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('vacancy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['vacancy_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_invitations');
    }
};
