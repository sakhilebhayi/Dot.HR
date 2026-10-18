<?php

namespace App\Actions\Hr;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Hr\LeaveBalance;
use App\Services\Hr\LeaveCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Files a leave request for an employee, enforcing the rules in one place:
 *  - the range must contain at least one working day (a half day only on a single day)
 *  - it may not overlap the employee's own pending or approved leave (two half days on
 *    one day, one am and one pm, are allowed)
 *  - for a tracked type that enforces its balance, the days in each leave year it touches
 *    may not exceed what is still available (entitlement - approved - pending)
 * Runs in a transaction that locks the employee row, so two requests filed at the same
 * moment cannot both pass the checks. Authorization (who may file for whom) is the
 * caller's job.
 */
class SubmitLeaveRequest
{
    public function __construct(private LeaveCalendar $calendar, private LeaveBalance $balances) {}

    /**
     * @param  array{leave_type_id: int|string, start_date: string, end_date: string, half_day?: string|null, reason?: string|null}  $data
     */
    public function __invoke(User $actor, Employee $employee, array $data): LeaveRequest
    {
        $type = LeaveType::withoutTeamScope(fn () => LeaveType::where('team_id', $employee->team_id)->findOrFail($data['leave_type_id']));
        $start = CarbonImmutable::parse($data['start_date'])->startOfDay();
        $end = CarbonImmutable::parse($data['end_date'])->startOfDay();
        $half = $data['half_day'] ?? null;

        if ($half !== null && ! $start->equalTo($end)) {
            throw ValidationException::withMessages(['half_day' => 'A half day can only be requested for a single day.']);
        }

        if ($this->calendar->workingDays($start, $end, $half) <= 0.0) {
            throw ValidationException::withMessages(['end_date' => 'That range has no working days in it (weekends and public holidays do not count).']);
        }

        return DB::transaction(function () use ($actor, $employee, $type, $start, $end, $half, $data): LeaveRequest {
            // Serialise concurrent requests for the same employee (a no-op lock on SQLite).
            Employee::withoutTeamScope(fn () => Employee::whereKey($employee->id)->lockForUpdate()->first());

            $this->assertNoOverlap($employee, $start, $end, $half);
            $this->assertWithinBalance($employee, $type, $start, $end, $half);

            return LeaveRequest::create([
                'team_id' => $employee->team_id,
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'type' => $type->slug,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'half_day' => $half,
                'reason' => filled($data['reason'] ?? null) ? $data['reason'] : null,
                'status' => LeaveRequest::STATUS_PENDING,
                'requested_by' => $actor->id,
            ]);
        });
    }

    private function assertNoOverlap(Employee $employee, CarbonImmutable $start, CarbonImmutable $end, ?string $half): void
    {
        $clashes = LeaveRequest::withoutGlobalScope('team')
            ->where('employee_id', $employee->id)
            ->holdingDates()
            ->overlapping($start->toDateString(), $end->toDateString())
            ->get()
            ->reject(fn (LeaveRequest $existing) => $this->opposingHalves($existing, $start, $end, $half));

        if ($clashes->isNotEmpty()) {
            throw ValidationException::withMessages(['start_date' => 'These dates overlap leave you already have pending or approved.']);
        }
    }

    /** True when the existing request and the new one are the am and pm of the same single day. */
    private function opposingHalves(LeaveRequest $existing, CarbonImmutable $start, CarbonImmutable $end, ?string $half): bool
    {
        return $half !== null
            && $existing->half_day !== null
            && $existing->half_day !== $half
            && $existing->start_date->isSameDay($existing->end_date)
            && $start->equalTo($end)
            && $existing->start_date->isSameDay($start);
    }

    private function assertWithinBalance(Employee $employee, LeaveType $type, CarbonImmutable $start, CarbonImmutable $end, ?string $half): void
    {
        if (! $type->isTracked() || ! $type->enforce_balance) {
            return;
        }

        $cursor = $start;

        while ($cursor->lessThanOrEqualTo($end)) {
            [$yearStart, $yearEnd] = $this->balances->leaveYear($cursor);
            $needed = $this->calendar->workingDaysWithin($start, $end, $yearStart, $yearEnd, $half);
            $snapshot = $this->balances->for($employee, $type, $cursor);

            if ($needed > $snapshot->available + 0.0001) {
                throw ValidationException::withMessages(['leave_type_id' => sprintf(
                    'That is %s day(s) of %s, but only %s are available in the leave year starting %s.',
                    $this->fmt($needed),
                    $type->name,
                    $this->fmt(max(0.0, $snapshot->available)),
                    $yearStart->format('j M Y'),
                )]);
            }

            $cursor = $yearEnd->addDay();
        }
    }

    private function fmt(float $days): string
    {
        return rtrim(rtrim(number_format($days, 1), '0'), '.');
    }
}
