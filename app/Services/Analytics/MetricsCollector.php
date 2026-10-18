<?php

namespace App\Services\Analytics;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Team;
use Carbon\CarbonInterface;

/**
 * The aggregate Dot.HR numbers one company publishes to Dot.Analytics, under Analytics' catalogue keys for the
 * `hr` platform (config/metrics.php there). Counts only: leave and employment data is person-sensitive, so never
 * a name, a contact detail, a leave reason or any row.
 *
 * Pure queries. The models' team scope fails closed with nobody signed in (console, queue), so every query here
 * opts out of exactly that one scope and filters by team_id itself; the result never depends on who, if anyone,
 * is signed in. Soft-deleted rows are left out by the models' own scope. "Today" is the UTC day.
 *
 * Reported (the same definitions the dashboard uses):
 *   headcount               employees whose status is not terminated
 *   on_leave_today          those with approved leave covering today (each person once)
 *   pending_leave_requests  requests still awaiting a decision, from employees who still exist
 */
class MetricsCollector
{
    /** @return array{headcount: int, on_leave_today: int, pending_leave_requests: int} */
    public function collect(Team|int $team, ?CarbonInterface $asOf = null): array
    {
        $teamId = $team instanceof Team ? $team->id : $team;
        $today = ($asOf ? $asOf->copy() : now())->utc()->startOfDay();

        $employees = fn () => Employee::query()->withoutGlobalScope('team')->where('employees.team_id', $teamId);

        return [
            'headcount' => $employees()->where('employees.status', '!=', 'terminated')->count(),
            'on_leave_today' => $employees()->onLeaveOn($today)->count(),
            'pending_leave_requests' => LeaveRequest::query()->withoutGlobalScope('team')
                ->where('leave_requests.team_id', $teamId)
                ->where('leave_requests.status', LeaveRequest::STATUS_PENDING)
                ->whereIn('leave_requests.employee_id', $employees()->select('employees.id'))
                ->count(),
        ];
    }
}
