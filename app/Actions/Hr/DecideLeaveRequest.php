<?php

namespace App\Actions\Hr;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Hr\LeaveBalance;
use App\Services\Hr\LeaveCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Approves or denies a leave request. Inside one transaction the row is locked and
 * re-read, and only a request that is still pending can be decided: two admins deciding
 * at once, or a double click, cannot flip a decision. Approving also re-checks the
 * balance against approved days only (this request is not counted twice), because other
 * requests may have been approved since it was filed.
 *
 * Who may decide (admin, never their own request) is LeaveRequestPolicy::decide, checked
 * by the caller before this runs.
 */
class DecideLeaveRequest
{
    public function __construct(private LeaveCalendar $calendar, private LeaveBalance $balances) {}

    /**
     * @param  'approved'|'denied'  $decision
     *
     * @throws LeaveStateException
     */
    public function __invoke(User $actor, LeaveRequest $leaveRequest, string $decision): LeaveRequest
    {
        return DB::transaction(function () use ($actor, $leaveRequest, $decision): LeaveRequest {
            $locked = LeaveRequest::whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw new LeaveStateException('This request was already '.$locked->status.' and cannot be changed.');
            }

            if ($decision === LeaveRequest::STATUS_APPROVED) {
                $this->assertBalanceStillCovers($locked);
            }

            $locked->update([
                'status' => $decision,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);

            return $locked;
        });
    }

    private function assertBalanceStillCovers(LeaveRequest $request): void
    {
        $type = $request->leaveType;
        $employee = $request->employee;

        if (! $type || ! $type->isTracked() || ! $type->enforce_balance) {
            return;
        }

        $cursor = CarbonImmutable::parse($request->start_date);
        $end = CarbonImmutable::parse($request->end_date);

        while ($cursor->lessThanOrEqualTo($end)) {
            [$yearStart, $yearEnd] = $this->balances->leaveYear($cursor);
            $needed = $this->calendar->workingDaysWithin($request->start_date, $request->end_date, $yearStart, $yearEnd, $request->half_day);
            $remaining = $this->balances->for($employee, $type, $cursor)->remaining;

            if ($needed > $remaining + 0.0001) {
                throw new LeaveStateException(sprintf(
                    'Cannot approve: %s day(s) requested but only %s of %s remain in the leave year starting %s. Deny it, or raise the entitlement first.',
                    rtrim(rtrim(number_format($needed, 1), '0'), '.'),
                    rtrim(rtrim(number_format(max(0.0, $remaining), 1), '0'), '.'),
                    $type->name,
                    $yearStart->format('j M Y'),
                ));
            }

            $cursor = $yearEnd->addDay();
        }
    }
}
