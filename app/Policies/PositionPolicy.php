<?php

namespace App\Policies;

use App\Models\Position;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Position/role definitions describe work, not workers (wiki.md §2) — lower
 * sensitivity than Employee — but still team-owned org structure, so access
 * is still gated to team members rather than left to the query alone.
 */
class PositionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Position $position): bool
    {
        return $user->belongsToTeam($position->team);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Position $position): bool
    {
        return $user->belongsToTeam($position->team);
    }

    public function delete(User $user, Position $position): bool
    {
        return $user->belongsToTeam($position->team);
    }
}
