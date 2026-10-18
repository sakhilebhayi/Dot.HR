<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Hr\HrAccess;
use Illuminate\Auth\Access\Response;

/**
 * Leave inherits the employee's sensitivity; the `reason` can carry health information.
 * Roles are defined once, in HrAccess:
 *   admin:    sees everything including reasons, decides requests, deletes, records leave
 *             on behalf of anyone.
 *   manager:  sees who is off and when (dates, status) but not the reason or type.
 *   self:     sees and requests only their own leave, and cancels their own pending request.
 *
 * Deciding is separated from requesting: nobody decides a request that is theirs, whether
 * the employee on it is their own linked record or they filed it themselves, and being an
 * admin does not change that. A team with a single admin therefore needs a second admin
 * to decide that admin's own leave. That is deliberate: a person must not approve their
 * own absence, and a recorded override would still be an approval by the requester.
 */
class LeaveRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return HrAccess::current($user)?->canListEmployees() ?? false;
    }

    public function view(User $user, LeaveRequest $leaveRequest): bool
    {
        $access = HrAccess::forTeamId($user, $leaveRequest->team_id);

        return $access !== null && ($access->isAdmin() || $access->isManager() || $this->isSubject($access, $leaveRequest));
    }

    /** The free-text reason (and the leave type, which can be as revealing). */
    public function viewReason(User $user, LeaveRequest $leaveRequest): bool
    {
        $access = HrAccess::forTeamId($user, $leaveRequest->team_id);

        return $access !== null && ($access->isAdmin() || $this->isSubject($access, $leaveRequest));
    }

    /** Opens the "request leave" form: admins (for anyone) and linked employees (for themselves). */
    public function create(User $user): bool
    {
        $access = HrAccess::current($user);

        return $access !== null && ($access->isAdmin() || ($access->employee() !== null && $access->employee()->status !== 'terminated'));
    }

    /** May file a request for this employee. */
    public function createFor(User $user, Employee $employee): bool
    {
        $access = HrAccess::forTeamId($user, $employee->team_id);

        if ($access === null) {
            return false;
        }

        if ($employee->status === 'terminated') {
            return false;
        }

        return $access->isAdmin() || $access->isSubject($employee);
    }

    /** Approve or deny. */
    public function decide(User $user, LeaveRequest $leaveRequest): Response
    {
        $access = HrAccess::forTeamId($user, $leaveRequest->team_id);

        if ($access === null || ! $access->isAdmin()) {
            return Response::deny();
        }

        if ($leaveRequest->requested_by === $user->id || $this->isSubject($access, $leaveRequest)) {
            return Response::deny('You cannot decide your own leave request. A second admin is needed.');
        }

        return Response::allow();
    }

    /** The employee withdraws their own request while it is still pending. */
    public function cancel(User $user, LeaveRequest $leaveRequest): bool
    {
        $access = HrAccess::forTeamId($user, $leaveRequest->team_id);

        return $access !== null && $leaveRequest->isPending() && $this->isSubject($access, $leaveRequest);
    }

    public function delete(User $user, LeaveRequest $leaveRequest): bool
    {
        return HrAccess::forTeamId($user, $leaveRequest->team_id)?->isAdmin() ?? false;
    }

    private function isSubject(HrAccess $access, LeaveRequest $leaveRequest): bool
    {
        $mine = $access->employee();

        return $mine !== null && $mine->id === $leaveRequest->employee_id;
    }
}
