<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business-level HR capabilities (R-32 Phase 3). InfoDot is the authority for who
 * belongs to a company and their team role, and it rewrites that role at every
 * sign-in — so being a member of a company never, by itself, lets anyone recruit.
 * An HR administrator of the business grants these here; they only count while the
 * holder is still a member (checked at use, see App\Support\Hr\HrAccess).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('capability', 32);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['team_id', 'user_id', 'capability']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_grants');
    }
};
