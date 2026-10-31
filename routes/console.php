<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retention: remove soft-deleted employees and leave requests past hr.retention_days.
Schedule::command('hr:purge-expired')->daily()->withoutOverlapping();

// Retention for ended applications, time records and read notifications (R-32 Phase 8).
Schedule::command('hr:retention')->dailyAt('03:20')->withoutOverlapping();

// Publish each company's daily aggregate numbers to Dot.Analytics (nothing happens until ANALYTICS_INGEST_URL and
// ANALYTICS_INGEST_SECRET are both set). Quiet hour, UTC, because the snapshot is dated by its UTC day.
Schedule::command('hr:publish-metrics')->dailyAt('02:10')->timezone('UTC')->withoutOverlapping();

// Warn HR and the holder, once, about certifications lapsing within 30 days (R-32 Phase 6a).
Schedule::command('hr:certification-reminders')->dailyAt('06:30')->withoutOverlapping();
Schedule::command('hr:engagement-reminders')->dailyAt('06:35')->withoutOverlapping();

// Proves the scheduler is alive; /ready fails when this has not run for 15 minutes (R-32 Phase 8).
Schedule::command('hr:heartbeat')->everyFiveMinutes();
