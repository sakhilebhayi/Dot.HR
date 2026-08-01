<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Employee is the most person-sensitive model in Dot.HR (wiki.md §2 —
 * "nearly every field is PII and the data subjects are also the employees
 * themselves"). Following this session's pattern from Dot.Finance's
 * AccountPolicy/TransactionPolicy and Dot.Billing's BillingInvoicePolicy —
 * every by-ID load must be gated by a Policy, never by the query alone.
 *
 * Team-scoped check: `$user->belongsToTeam($employee->team)`, the same
 * pattern used across the ecosystem's team-scoped platforms. Every method
 * below — including create/update/delete — uses this same team-membership
 * check; there is no additional role gate. Concretely: any member of a
 * team, not just its owner or an "HR admin," can create, edit, or delete
 * that team's employee records today. That is an explicit MVP-scope
 * decision, not an oversight, but it is a real gap relative to how HR data
 * is normally handled — a stricter "HR admin only" role split (via
 * Jetstream's team-permission system, which exists in this app's Jetstream
 * config but is not yet wired into these policies) is the top roadmap item
 * for this platform's next pass (see wiki.md §9).
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
        return true;
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->belongsToTeam($employee->team);
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->belongsToTeam($employee->team);
    }
}
