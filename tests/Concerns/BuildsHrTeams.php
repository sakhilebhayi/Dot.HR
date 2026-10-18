<?php

namespace Tests\Concerns;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Fixtures for the HR role matrix: an owner (HR admin), a second admin, an
 * editor (manager-lite), a plain member, and employees linked to accounts.
 */
trait BuildsHrTeams
{
    protected function owner(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    protected function memberOf(User $owner, string $role): User
    {
        $member = User::factory()->create();
        $owner->currentTeam->users()->attach($member, ['role' => $role]);
        $member->forceFill(['current_team_id' => $owner->currentTeam->id])->save();

        return $member->fresh();
    }

    protected function employeeIn(Team $team, ?User $user = null, array $attributes = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'team_id' => $team->id,
            'position_id' => null,
            'user_id' => $user?->id,
            'email' => $user?->email,
            'status' => 'active',
        ], $attributes));
    }

    protected function leaveType(Team $team, string $slug = 'annual'): LeaveType
    {
        return LeaveType::withoutTeamScope(fn () => LeaveType::where('team_id', $team->id)->where('slug', $slug)->firstOrFail());
    }

    protected function leaveFor(Employee $employee, string|CarbonInterface $start, string|CarbonInterface $end, array $attributes = []): LeaveRequest
    {
        return LeaveRequest::factory()->create(array_merge([
            'team_id' => $employee->team_id,
            'employee_id' => $employee->id,
            'leave_type_id' => $this->leaveType($employee->team)->id,
            'type' => 'annual',
            'start_date' => $start,
            'end_date' => $end,
            'requested_by' => $employee->user_id ?? User::factory()->create()->id,
            'reason' => null,
            'status' => 'pending',
        ], $attributes));
    }
}
