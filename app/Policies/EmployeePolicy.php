<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Employee is the most person-sensitive model in Dot.HR (wiki.md §2 —
 * "nearly every field is PII and the data subjects are also the employees
 * themselves"). Following this session's pattern from Dot.Finance's
 * AccountPolicy/TransactionPolicy and Dot.Billing's BillingInvoicePolicy —
 * every by-ID load must be gated by a Policy, never by the query alone.
 *
 * `view`/`viewAny` stay open to any team member: day-to-day roster lookups
 * are legitimately broad within a team (wiki.md §9). `create`/`update`/
 * `delete` are now role-gated on top of team membership — this closes the
 * gap the previous docblock revision flagged as the top roadmap item
 * (wiki.md §9): "any member of a team, not just an admin, can create,
 * edit, or delete that team's employee records."
 *
 * The gate uses Jetstream's team-role system: `$user->hasTeamRole($team,
 * 'admin')`, plus an explicit `ownsTeam($team)` check for the team owner
 * (Jetstream's `hasTeamRole()` already treats the owner as satisfying any
 * role check, but the explicit check documents the intent and is harmless
 * belt-and-suspenders). This app's `JetstreamServiceProvider` only defines
 * two team roles — `admin` (create/read/update/delete) and `editor`
 * (read/create/update) — with no per-domain permissions like
 * `employees:update` defined anywhere in the config. Rather than gate on
 * the generic `update`/`delete` permission strings (which `editor` already
 * holds for `update`, so it would not actually close the gap for that
 * role), this policy gates mutations on the `admin` team role directly,
 * matching the wiki's stated goal of an "HR admin only" restriction.
 */
class EmployeePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->belongsToTeam($employee->team);
    }

    public function create(User $user): bool
    {
        return $user->currentTeam && $this->isTeamAdmin($user, $user->currentTeam);
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->belongsToTeam($employee->team) && $this->isTeamAdmin($user, $employee->team);
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->belongsToTeam($employee->team) && $this->isTeamAdmin($user, $employee->team);
    }

    /**
     * Team owners and members holding the `admin` team role may mutate HR
     * records; plain `editor` members (and any future non-admin role) may
     * not.
     */
    private function isTeamAdmin(User $user, Team $team): bool
    {
        return $user->ownsTeam($team) || $user->hasTeamRole($team, 'admin');
    }
}
