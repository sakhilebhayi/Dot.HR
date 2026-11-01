<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each business's own hiring steps, and what hiring costs (R-32 brief §5).
 *
 * The stages stay fixed, because offers, interviews, retention and what candidates see depend
 * on them. A business adds its own named steps inside the open stages (a phone screen under In
 * review, a reference check under Offer); an application can sit on one. Candidates never see
 * steps. Hiring costs are what a role cost to fill, recorded by hand; cost per hire divides them
 * by the people hired and says it counts recorded outside costs only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hiring_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('stage', 20); // review | shortlisted | interview | offer
            $table->string('name', 60);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'stage']);
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->foreignId('hiring_step_id')->nullable()->after('stage')->constrained('hiring_steps')->nullOnDelete();
        });

        Schema::create('hiring_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('vacancy_id')->constrained('vacancies')->cascadeOnDelete();
            $table->string('kind', 20); // advertising | agency | assessments | travel | other
            $table->string('description', 160)->nullable();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('ZAR');
            $table->date('incurred_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['team_id', 'incurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hiring_costs');
        Schema::table('applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hiring_step_id');
        });
        Schema::dropIfExists('hiring_steps');
    }
};
