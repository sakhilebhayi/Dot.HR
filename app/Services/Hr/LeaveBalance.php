<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * An employee's balance for one leave type in one leave year.
 *
 *   entitlement = annual_days + carried over from the previous year
 *   used        = approved working days inside the leave year
 *   pending     = working days of still-pending requests inside the leave year
 *   remaining   = entitlement - used                (what the employee sees as the balance)
 *   available   = entitlement - used - pending      (what a new request is checked against,
 *                                                    so two pending requests cannot together exceed it)
 *
 * Carry-over is the smaller of the type's carry_over_days and what was left unused last
 * year (annual_days minus last year's approved days). It deliberately does not chain
 * further back, keeping the rule simple and predictable. A type with no annual_days is
 * untracked: `tracked` is false and nothing is enforced.
 */
class LeaveBalance
{
    public function __construct(private LeaveCalendar $calendar) {}

    public function for(Employee $employee, LeaveType $type, CarbonInterface|string $on): LeaveBalanceSnapshot
    {
        [$start, $end] = $this->leaveYear($on);

        if (! $type->isTracked()) {
            return new LeaveBalanceSnapshot(false, 0.0, 0.0, $this->days($employee, $type, 'approved', $start, $end), $this->days($employee, $type, 'pending', $start, $end), $start, $end);
        }

        $carried = $this->carriedOver($employee, $type, $start);
        $entitlement = (float) $type->annual_days + $carried;

        return new LeaveBalanceSnapshot(
            true,
            $entitlement,
            $carried,
            $this->days($employee, $type, 'approved', $start, $end),
            $this->days($employee, $type, 'pending', $start, $end),
            $start,
            $end,
        );
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function leaveYear(CarbonInterface|string $on): array
    {
        $on = CarbonImmutable::parse($on)->startOfDay();
        $month = (int) config('hr.leave_year_start_month', 1);

        $start = $on->setDate($on->year, $month, 1);

        if ($start->greaterThan($on)) {
            $start = $start->subYear();
        }

        return [$start, $start->addYear()->subDay()];
    }

    private function carriedOver(Employee $employee, LeaveType $type, CarbonImmutable $yearStart): float
    {
        $cap = (float) $type->carry_over_days;

        if ($cap <= 0) {
            return 0.0;
        }

        $previousStart = $yearStart->subYear();
        $previousEnd = $yearStart->subDay();
        $left = (float) $type->annual_days - $this->days($employee, $type, 'approved', $previousStart, $previousEnd);

        return max(0.0, min($cap, $left));
    }

    private function days(Employee $employee, LeaveType $type, string $status, CarbonImmutable $from, CarbonImmutable $to): float
    {
        return (float) LeaveRequest::withoutGlobalScope('team')
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('status', $status)
            ->overlapping($from->toDateString(), $to->toDateString())
            ->get(['start_date', 'end_date', 'half_day'])
            ->sum(fn (LeaveRequest $r) => $this->calendar->workingDaysWithin($r->start_date, $r->end_date, $from, $to, $r->half_day));
    }
}
