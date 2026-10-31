<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Proves the scheduler is running (R-32 Phase 8). /ready reports how long ago this last ran;
 * reminders, retention and metrics all depend on the same cron line.
 */
class SchedulerHeartbeat extends Command
{
    public const KEY = 'hr:scheduler-heartbeat';

    protected $signature = 'hr:heartbeat';

    protected $description = 'Record that the scheduler ran (read by /ready).';

    public function handle(): int
    {
        Cache::forever(self::KEY, now()->getTimestamp());

        return self::SUCCESS;
    }
}
