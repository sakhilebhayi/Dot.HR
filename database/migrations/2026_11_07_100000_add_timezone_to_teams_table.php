<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each business's own time zone (R-32 follow-up). Null keeps the app-wide HR_LOCAL_TIMEZONE.
 * Shift and attendance times are stored as the wall-clock times people typed, so setting a zone
 * needs no data migration: it changes what "now" and "today" are for that business only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('teams', 'timezone')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->string('timezone', 64)->nullable()->after('weekly_ordinary_hours');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('teams', 'timezone')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->dropColumn('timezone');
            });
        }
    }
};
