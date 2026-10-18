<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only access and change log. Deliberately no foreign keys: a deleted team,
 * user or employee must never cascade into (or null out) the record of what happened.
 * Holds field NAMES and non-sensitive before/after values only, never a leave reason
 * or contact detail. The model refuses update and delete; nothing purges it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_audit_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('action', 60);
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('fields')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['team_id', 'created_at']);
            $table->index('action');
            $table->index('actor_user_id');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_audit_log');
    }
};
