<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contractor self-service (R-32 brief §12, follow-up to Phase 6b). A contractor sends invoices
 * from My work with the invoice file attached (encrypted at rest, checked on every read, as
 * employment documents are), and keeps their own business details current. Bank details are
 * deliberately not stored: a changed bank account is the classic invoice-fraud route, so payment
 * details stay in the business's own verified supplier records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contractor_invoices', function (Blueprint $table) {
            $table->date('issued_on')->nullable()->after('received_on');
            $table->foreignId('engagement_milestone_id')->nullable()->after('engagement_id')->constrained('engagement_milestones')->nullOnDelete();
            $table->string('source', 12)->default('hr')->after('status'); // hr | contractor
            $table->foreignId('submitted_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
            $table->string('file_path', 255)->nullable();
            $table->string('file_name', 200)->nullable();
            $table->string('file_mime', 100)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->char('file_sha256', 64)->nullable();
        });

        Schema::create('contractor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('employee_id')->unique()->constrained('employees')->cascadeOnDelete();
            $table->string('trading_name', 160)->nullable();
            $table->string('registration_number', 40)->nullable();
            $table->string('vat_number', 20)->nullable();
            $table->string('billing_address', 255)->nullable();
            $table->string('invoice_email', 190)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_profiles');
        Schema::table('contractor_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('engagement_milestone_id');
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropColumn(['issued_on', 'source', 'file_path', 'file_name', 'file_mime', 'file_size', 'file_sha256']);
        });
    }
};
