<?php

namespace App\Observers;

use App\Models\LeaveRequest;
use App\Notifications\LeaveRequestSubmittedNotification;
use App\Services\Hr\Audit;
use App\Services\Notify\NotifyClient;
use Illuminate\Support\Facades\Notification;

/**
 * Two jobs, on every code path (controller, factory, seeder, a future API):
 *
 * 1. Audit trail: creation, the decision (approved / denied), cancellation, deletion and
 *    purge are written to the audit log. The reason text is never logged.
 * 2. On creation, fires LeaveRequestSubmittedNotification (and a leave.requested event to Dot.Notify, for
 *    email) for every admin/owner of the
 *    leave request's team, so approvers are alerted. Recipients are admins/owner only,
 *    not every team member, matching HrAccess::isAdmin().
 *    See docs/superpowers/specs/2026-08-09-leave-request-notification-wiring-design.md.
 */
class LeaveRequestObserver
{
    public function created(LeaveRequest $leaveRequest): void
    {
        Audit::record('leave.created', $leaveRequest, ['leave_type_id', 'start_date', 'end_date', 'half_day'], Audit::diff($leaveRequest, ['start_date', 'end_date', 'half_day', 'leave_type_id'], LeaveRequest::AUDIT_FIELDS) + [
            'status' => ['from' => null, 'to' => $leaveRequest->status],
        ]);

        $leaveRequest->loadMissing('team');

        $team = $leaveRequest->team;

        $approvers = $team->administrators();

        // Building the message reads the employee, which is team-scoped; there may be no signed-in user here.
        LeaveRequest::withoutTeamScope(fn () => Notification::send($approvers, new LeaveRequestSubmittedNotification($leaveRequest)));

        // The same approvers also get an email via Dot.Notify, when it is set up. This never throws or waits on
        // Notify, and nothing here changes who is told in-app.
        $notify = app(NotifyClient::class);

        foreach ($approvers as $approver) {
            $notify->leaveRequested($leaveRequest, $approver);
        }
    }

    public function updated(LeaveRequest $leaveRequest): void
    {
        $fields = Audit::changedFields($leaveRequest);

        if ($fields === []) {
            return;
        }

        $action = match ($leaveRequest->status) {
            LeaveRequest::STATUS_APPROVED => 'leave.approved',
            LeaveRequest::STATUS_DENIED => 'leave.denied',
            LeaveRequest::STATUS_CANCELLED => 'leave.cancelled',
            default => 'leave.updated',
        };

        if (! in_array('status', $fields, true)) {
            $action = 'leave.updated';
        }

        Audit::record($action, $leaveRequest, $fields, Audit::diff($leaveRequest, $fields, LeaveRequest::AUDIT_FIELDS));
    }

    public function deleted(LeaveRequest $leaveRequest): void
    {
        if (! $leaveRequest->isForceDeleting()) {
            Audit::record('leave.deleted', $leaveRequest);
        }
    }

    public function restored(LeaveRequest $leaveRequest): void
    {
        Audit::record('leave.restored', $leaveRequest);
    }

    public function forceDeleted(LeaveRequest $leaveRequest): void
    {
        Audit::record('leave.purged', $leaveRequest);
    }
}
