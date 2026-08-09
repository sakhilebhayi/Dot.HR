<?php

namespace App\Observers;

use App\Models\LeaveRequest;
use App\Notifications\LeaveRequestSubmittedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Fires LeaveRequestSubmittedNotification for every admin/owner of the
 * leave request's team, on every LeaveRequest creation regardless of
 * entry point (controller, factory, seeder, or a future API) — see
 * docs/superpowers/specs/2026-08-09-leave-request-notification-wiring-design.md.
 *
 * Recipients are admins/owner only, not every team member — this
 * notification exists to alert approvers, matching
 * LeaveRequestPolicy::isTeamAdmin()'s exact same predicate.
 */
class LeaveRequestObserver
{
    public function created(LeaveRequest $leaveRequest): void
    {
        $leaveRequest->loadMissing('team');

        $team = $leaveRequest->team;

        $approvers = $team->allUsers()->filter(
            fn ($user) => $user->ownsTeam($team) || $user->hasTeamRole($team, 'admin')
        );

        Notification::send($approvers, new LeaveRequestSubmittedNotification($leaveRequest));
    }
}
