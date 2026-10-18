<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Support\Hr\HrAccess;

/**
 * Who may do what with employee records. The roles are defined once, in HrAccess:
 *   admin (owner / Jetstream admin): everything.
 *   manager (Jetstream editor):      list and open a record, basics only.
 *   self (linked account):           their own record, in full.
 * Mutations, linking an account, and restoring are admin-only. Team membership alone
 * grants nothing: a plain member with no linked record cannot even list employees.
 * Cross-team access never reaches here: HasTeamScope makes the row not exist (404).
 */
class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return HrAccess::current($user)?->canListEmployees() ?? false;
    }

    public function view(User $user, Employee $employee): bool
    {
        $access = HrAccess::forTeamId($user, $employee->team_id);

        return $access !== null && ($access->isAdmin() || $access->isManager() || $access->isSubject($employee));
    }

    /** Email, phone, employment dates and the employee's leave history. */
    public function viewContact(User $user, Employee $employee): bool
    {
        $access = HrAccess::forTeamId($user, $employee->team_id);

        return $access !== null && $access->canSeeContact($employee);
    }

    public function create(User $user): bool
    {
        return HrAccess::current($user)?->isAdmin() ?? false;
    }

    public function update(User $user, Employee $employee): bool
    {
        return $this->admin($user, $employee);
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $this->admin($user, $employee);
    }

    public function restore(User $user, Employee $employee): bool
    {
        return $this->admin($user, $employee);
    }

    /** Link or unlink the employee record to a team member's account. */
    public function link(User $user, Employee $employee): bool
    {
        return $this->admin($user, $employee);
    }

    private function admin(User $user, Employee $employee): bool
    {
        return HrAccess::forTeamId($user, $employee->team_id)?->isAdmin() ?? false;
    }
}
