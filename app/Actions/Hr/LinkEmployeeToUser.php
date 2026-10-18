<?php

namespace App\Actions\Hr;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The admin "link to account" action: ties an employee record to a member of the same
 * team, which is what makes that member "self" for the record. One account links to one
 * active employee per team (also enforced by a partial unique index).
 */
class LinkEmployeeToUser
{
    public function __invoke(Employee $employee, User $user): Employee
    {
        if (! $user->belongsToTeam($employee->team)) {
            throw ValidationException::withMessages(['user_id' => 'That person is not a member of this team.']);
        }

        $taken = Employee::withoutTeamScope(fn () => Employee::where('team_id', $employee->team_id)
            ->where('user_id', $user->id)->where('id', '!=', $employee->id)->exists());

        if ($taken) {
            throw ValidationException::withMessages(['user_id' => 'That account is already linked to another employee in this team.']);
        }

        $employee->forceFill(['user_id' => $user->id])->save();
        $user->forgetHrEmployee();

        return $employee;
    }

    public function unlink(Employee $employee): Employee
    {
        $employee->user?->forgetHrEmployee();
        $employee->forceFill(['user_id' => null])->save();

        return $employee;
    }
}
