<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The individual's professional identity (R-32 Phase 3). It belongs to the PERSON,
 * not to any company: one per user, carried across every workspace they hold.
 * Nothing here is ever copied from another Dot platform without the person's own act.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professional_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            // The share link's handle; random, never derived from the person's name or email.
            $table->string('handle', 24)->unique();
            $table->string('headline', 140)->nullable();
            $table->text('about')->nullable();
            $table->string('location', 120)->nullable();
            $table->string('availability', 24)->default('not_looking');
            $table->json('work_arrangements')->nullable();
            $table->json('work_types')->nullable();
            $table->string('visibility', 24)->default('private');
            $table->timestamps();
        });

        Schema::create('profile_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_profile_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            // Lower-cased for matching (Phase 4); the display name keeps the person's casing.
            $table->string('normalized', 60)->index();
            $table->unsignedSmallInteger('position')->default(0);
            $table->unique(['professional_profile_id', 'normalized']);
        });

        Schema::create('profile_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_profile_id')->constrained()->cascadeOnDelete();
            // experience | education | certification
            $table->string('kind', 16);
            $table->string('title', 140);
            $table->string('organisation', 140)->nullable();
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->boolean('is_current')->default(false);
            // For certifications and licences: when it lapses.
            $table->date('expires_on')->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['professional_profile_id', 'kind']);
        });

        Schema::create('profile_blocked_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['professional_profile_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_blocked_teams');
        Schema::dropIfExists('profile_entries');
        Schema::dropIfExists('profile_skills');
        Schema::dropIfExists('professional_profiles');
    }
};
