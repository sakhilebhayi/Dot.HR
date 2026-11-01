<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employment documents and company policies (R-32 §10: contracts, contract acceptance, policy
 * acknowledgement, document submission, "access employment documents").
 *
 * The files live in Dot.HR's private storage, encrypted with the application key — not in
 * Dot.Files, where every member of a company can open every company file: contracts carry pay
 * and identity copies, which the brief keeps from managers and ordinary team members. A
 * document with no employee is a company policy, which every employee may open.
 *
 * Acknowledgements record that a person read a policy or accepted a document: who, when, and
 * the name they typed. That is an electronic acceptance, not an advanced electronic signature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('title', 160);
            $table->string('file_name', 200);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->string('path', 255);
            $table->char('sha256', 64);
            $table->string('source', 10)->default('hr'); // hr | employee
            $table->boolean('shared')->default(false); // the employee may see it (always true for policies and submissions)
            $table->boolean('requires_acceptance')->default(false);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['team_id', 'employee_id']);
            $table->index(['team_id', 'kind']);
        });

        Schema::create('hr_document_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('hr_document_id')->constrained('hr_documents')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('typed_name', 120)->nullable();
            $table->timestamp('acknowledged_at');
            $table->timestamps();
            $table->unique(['hr_document_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_document_acknowledgements');
        Schema::dropIfExists('hr_documents');
    }
};
