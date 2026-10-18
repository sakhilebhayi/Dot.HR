<?php

namespace App\Policies;

use App\Models\Position;
use App\Models\User;
use App\Support\Hr\HrAccess;

/**
 * Positions describe work, not workers (wiki.md section 2). Admins manage them and
 * assign employees; managers (editors) can read the list of positions and how many
 * people hold each, but not who; everyone else sees nothing here. Roles are defined once,
 * in HrAccess.
 */
class PositionPolicy
{
    public function viewAny(User $user): bool
    {
        $access = HrAccess::current($user);

        return $access !== null && ($access->isAdmin() || $access->isManager());
    }

    public function view(User $user, Position $position): bool
    {
        $access = HrAccess::forTeamId($user, $position->team_id);

        return $access !== null && ($access->isAdmin() || $access->isManager());
    }

    public function create(User $user): bool
    {
        return HrAccess::current($user)?->isAdmin() ?? false;
    }

    public function update(User $user, Position $position): bool
    {
        return HrAccess::forTeamId($user, $position->team_id)?->isAdmin() ?? false;
    }

    public function delete(User $user, Position $position): bool
    {
        return $this->update($user, $position);
    }
}
