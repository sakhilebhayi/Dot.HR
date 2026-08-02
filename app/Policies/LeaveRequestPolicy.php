<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Leave requests inherit Employee's sensitivity (the `reason` field can
 * carry incidental health information — see LeaveRequest's docblock) so the
 * same team-scoped check applies: `$user->belongsToTeam($leaveRequest->team)`.
 * team_id is denormalized onto leave_requests specifically so this check is
 * a single-column comparison rather than a join through employees, matching
 * the "explicit ownership Policy, not the query alone" rule from
 * os/17-Security.md's standing checklist (finding #1/#5 pattern).
 *
 * `view`/`viewAny` stay open to any team member — leave-approval workflows
 * need broad visibility within a team (wiki.md §9). `create`, `update`
 * (which also gates the `approve`/`deny` endpoints, per
 * LeaveRequestController) and `delete` are now restricted to the team's
 * `admin` role or owner, via the same `hasTeamRole($team, 'admin')`
 * mechanism as EmployeePolicy — see that policy's docblock for why a
 * generic `create`/`update`/`delete` permission string wasn't used
 * instead. `create` is included deliberately, not just update/delete:
 * this app has no concept of "an Employee's own User account," so any
 * member submitting a leave request picks an arbitrary `employee_id` from
 * the team rather than necessarily their own — gating creation the same
 * as the other mutations avoids a side door where a non-admin could
 * fabricate leave records for another employee. This closes the same
 * top-priority gap flagged in wiki.md §9 for LeaveRequest.
 */
class LeaveRequestPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LeaveRequest $leaveRequest): bool
    {
        return $user->belongsToTeam($leaveRequest->team);
    }

    public function create(User $user): bool
    {
        return $user->currentTeam && $this->isTeamAdmin($user, $user->currentTeam);
    }

    public function update(User $user, LeaveRequest $leaveRequest): bool
    {
        return $user->belongsToTeam($leaveRequest->team) && $this->isTeamAdmin($user, $leaveRequest->team);
    }

    public function delete(User $user, LeaveRequest $leaveRequest): bool
    {
        return $user->belongsToTeam($leaveRequest->team) && $this->isTeamAdmin($user, $leaveRequest->team);
    }

    private function isTeamAdmin(User $user, Team $team): bool
    {
        return $user->ownsTeam($team) || $user->hasTeamRole($team, 'admin');
    }
}
