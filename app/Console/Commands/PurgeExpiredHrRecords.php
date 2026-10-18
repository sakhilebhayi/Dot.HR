<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\Hr\Audit;
use Illuminate\Console\Command;

/**
 * Retention: permanently removes employees and leave requests that were soft-deleted more
 * than config('hr.retention_days') days ago. An expired employee takes their leave history
 * with them. Every purged row writes an audit entry (action employee.purged / leave.purged,
 * subject id only, no actor, no personal data). Audit rows themselves are never purged.
 *
 * Runs with no signed-in user and across every team, so it opts out of HasTeamScope
 * explicitly, and records no actor even if run from a logged-in context.
 */
class PurgeExpiredHrRecords extends Command
{
    protected $signature = 'hr:purge-expired {--dry-run : Report what would be purged without deleting anything}';

    protected $description = 'Permanently delete soft-deleted employees and leave requests older than the retention period';

    private const BATCH = 200;

    public function handle(): int
    {
        $days = (int) config('hr.retention_days');

        if ($days < 1) {
            $this->error('hr.retention_days must be at least 1; refusing to purge.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $dry = (bool) $this->option('dry-run');

        [$employees, $leave] = Employee::withoutTeamScope(fn () => Audit::asSystem(
            fn () => $dry ? $this->count($cutoff) : $this->purge($cutoff)
        ));

        $this->info(sprintf(
            '%s %d employee record(s) and %d leave request(s) soft-deleted before %s (retention %d days).',
            $dry ? 'Would purge' : 'Purged',
            $employees,
            $leave,
            $cutoff->toDateTimeString(),
            $days,
        ));

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: int} */
    private function count($cutoff): array
    {
        $expired = Employee::onlyTrashed()->where('deleted_at', '<=', $cutoff);

        $leave = LeaveRequest::withTrashed()
            ->where(fn ($q) => $q
                ->whereIn('employee_id', (clone $expired)->select('id'))
                ->orWhere(fn ($q) => $q->whereNotNull('deleted_at')->where('deleted_at', '<=', $cutoff)))
            ->count();

        return [(clone $expired)->count(), $leave];
    }

    /** @return array{0: int, 1: int} */
    private function purge($cutoff): array
    {
        $employees = 0;
        $leave = 0;

        // An expired employee goes with their whole leave history.
        do {
            $batch = Employee::onlyTrashed()->where('deleted_at', '<=', $cutoff)->orderBy('id')->limit(self::BATCH)->get();

            foreach ($batch as $employee) {
                LeaveRequest::withTrashed()->where('employee_id', $employee->id)->get()->each(function (LeaveRequest $request) use (&$leave): void {
                    $request->forceDelete();
                    $leave++;
                });

                $employee->forceDelete();
                $employees++;
            }
        } while ($batch->count() === self::BATCH);

        // Individually expired leave requests of employees who remain.
        do {
            $batch = LeaveRequest::onlyTrashed()->where('deleted_at', '<=', $cutoff)->orderBy('id')->limit(self::BATCH)->get();

            foreach ($batch as $request) {
                $request->forceDelete();
                $leave++;
            }
        } while ($batch->count() === self::BATCH);

        return [$employees, $leave];
    }
}
