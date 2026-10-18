<?php

namespace App\Policies;

use App\Models\LeaveType;
use App\Models\User;
use App\Support\Hr\HrAccess;

/** Leave types are configuration: HR admins manage them. Any member reads them through the request form. */
class LeaveTypePolicy
{
    public function viewAny(User $user): bool
    {
        return HrAccess::current($user)?->isAdmin() ?? false;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, LeaveType $type): bool
    {
        return HrAccess::forTeamId($user, $type->team_id)?->isAdmin() ?? false;
    }

    public function delete(User $user, LeaveType $type): bool
    {
        return $this->update($user, $type);
    }
}
