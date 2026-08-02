<?php

namespace App\Policies;

use App\Models\Position;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Position/role definitions describe work, not workers (wiki.md §2) — lower
 * sensitivity than Employee — but still team-owned org structure, so access
 * is still gated to team members rather than left to the query alone.
 *
 * `view`/`viewAny` stay open to any team member. `create`/`update`/`delete`
 * are now restricted to the team's `admin` role or owner, via the same
 * `hasTeamRole($team, 'admin')` mechanism as EmployeePolicy/
 * LeaveRequestPolicy — Position had the identical "any team member can
 * mutate" gap, just not called out by name in wiki.md §9's roadmap item
 * (which named Employee/LeaveRequest/Position together). Restructuring who
 * can be hired into or removed from a role is an org-structure decision,
 * not day-to-day roster work, so it belongs with the other HR-admin-only
 * mutations.
 */
class PositionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Position $position): bool
    {
        return $user->belongsToTeam($position->team);
    }

    public function create(User $user): bool
    {
        return $user->currentTeam && $this->isTeamAdmin($user, $user->currentTeam);
    }

    public function update(User $user, Position $position): bool
    {
        return $user->belongsToTeam($position->team) && $this->isTeamAdmin($user, $position->team);
    }

    public function delete(User $user, Position $position): bool
    {
        return $user->belongsToTeam($position->team) && $this->isTeamAdmin($user, $position->team);
    }

    private function isTeamAdmin(User $user, Team $team): bool
    {
        return $user->ownsTeam($team) || $user->hasTeamRole($team, 'admin');
    }
}
