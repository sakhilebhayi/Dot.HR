<?php

namespace App\Policies;

use App\Models\LeaveRequest;
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
        return true;
    }

    public function update(User $user, LeaveRequest $leaveRequest): bool
    {
        return $user->belongsToTeam($leaveRequest->team);
    }

    public function delete(User $user, LeaveRequest $leaveRequest): bool
    {
        return $user->belongsToTeam($leaveRequest->team);
    }
}
