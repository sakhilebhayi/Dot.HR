<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training costs and budgets (R-32 brief §10, follow-up to Phase 6b). Each training record can
 * carry what it costs and who pays: the business, the person, or a sponsor (a SETA grant, a
 * supplier, a client). Budgets are amounts for a period, for the whole business or one
 * department; only training the business pays counts against them, booked and spent apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_records', function (Blueprint $table) {
            $table->decimal('cost', 12, 2)->nullable()->after('hours');
            $table->char('currency', 3)->default('ZAR')->after('cost');
            $table->string('paid_by', 10)->default('business')->after('currency'); // business | employee | sponsor
            $table->string('sponsor', 120)->nullable()->after('paid_by');
        });

        Schema::create('training_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('department', 80)->nullable(); // null: the whole business
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('ZAR');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['team_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_budgets');
        Schema::table('training_records', function (Blueprint $table) {
            $table->dropColumn(['cost', 'currency', 'paid_by', 'sponsor']);
        });
    }
};
