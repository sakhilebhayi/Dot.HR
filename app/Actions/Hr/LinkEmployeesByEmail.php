<?php

namespace App\Actions\Hr;

use App\Models\Employee;
use App\Models\User;
use App\Support\Hr\EmailIndex;

/**
 * Automatic linking by email. Only a *verified* account email is ever matched, only
 * against an unlinked employee in a team the account really belongs to, and only if that
 * account is not already linked to another employee there. Matching uses the keyed
 * blind index, case-insensitively. An unverified address proves nothing, so it never links.
 *
 * Runs when someone signs in (any way, including InfoDot) and when an admin saves an
 * employee whose email matches an existing member.
 */
class LinkEmployeesByEmail
{
    /** Link every employee whose email matches this signed-in user. Returns how many were linked. */
    public function forUser(User $user): int
    {
        $hash = EmailIndex::hash($user->email);

        if ($hash === null || $user->email_verified_at === null) {
            return 0;
        }

        $teamIds = $user->allTeams()->pluck('id')->all();

        if ($teamIds === []) {
            return 0;
        }

        $linked = 0;

        $candidates = Employee::withoutTeamScope(fn () => Employee::whereIn('team_id', $teamIds)
            ->where('email_hash', $hash)->whereNull('user_id')->get());

        foreach ($candidates as $employee) {
            if ($this->link($employee, $user)) {
                $linked++;
            }
        }

        if ($linked > 0) {
            $user->forgetHrEmployee();
        }

        return $linked;
    }

    /** Link this (unlinked) employee to a verified team member with the same email, if there is one. */
    public function forEmployee(Employee $employee): bool
    {
        if ($employee->user_id !== null || $employee->email_hash === null) {
            return false;
        }

        $team = $employee->team;

        $match = $team->allUsers()->first(fn (User $member) => $member->email_verified_at !== null
            && EmailIndex::hash($member->email) === $employee->email_hash);

        return $match !== null && $this->link($employee, $match);
    }

    private function link(Employee $employee, User $user): bool
    {
        if (! $user->belongsToTeam($employee->team)) {
            return false;
        }

        $taken = Employee::withoutTeamScope(fn () => Employee::where('team_id', $employee->team_id)
            ->where('user_id', $user->id)->exists());

        if ($taken) {
            return false;
        }

        $employee->forceFill(['user_id' => $user->id])->save();
        $user->forgetHrEmployee();

        return true;
    }
}
