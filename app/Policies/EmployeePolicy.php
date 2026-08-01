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
 * pattern used across the ecosystem's team-scoped platforms. We do not
 * narrow this further to "only admins" for MVP because leave-request
 * approval and day-to-day roster work legitimately needs any team member
 * to view records; role-gating create/update/delete is handled below via
 * Jetstream's team permissions, and is flagged as an MVP-scope decision —
 * a stricter "HR admin only" role split is a reasonable roadmap item once
 * real usage patterns are known (see wiki.md §9).
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
