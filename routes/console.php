<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retention: remove soft-deleted employees and leave requests past hr.retention_days.
Schedule::command('hr:purge-expired')->daily()->withoutOverlapping();
