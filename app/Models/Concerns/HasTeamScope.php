<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Dot.HR is Jetstream-Teams-scoped, not single-user (see EmployeePolicy /
 * LeaveRequestPolicy / PositionPolicy docblocks and wiki.md §1). Every model
 * that owns a `team_id` column applies this trait so a query against it is
 * scoped to the authenticated user's *current* team by default, the same
 * way Dot.Mines' HasTeamFilters (and Dot.Finance's user-scoped
 * HasUserScope, this trait's direct sibling from that pilot) scopes every
 * tenant-owned model automatically — the goal is that a forgotten
 * where('team_id', ...) call in a future controller can no longer leak
 * another team's rows, because the model itself never returns unscoped
 * results while a user is authenticated.
 *
 * Fail-closed on a null current team: if the user is authenticated but has
 * no `currentTeam` (Jetstream allows this transiently — e.g. mid
 * team-deletion/invitation flows), the scope constrains the query to
 * `whereRaw('1 = 0')` rather than falling through to an unscoped query.
 * Returning zero rows is always safe here; leaking every team's rows never
 * is. This mirrors why implicit route-model binding on these tables now
 * 404s instead of relying solely on a Policy for cross-team requests — see
 * HrAuthorizationTest.php.
 *
 * Mass assignment still sets team_id explicitly at create time (see each
 * controller's store()); this scope only governs reads.
 */
trait HasTeamScope
{
    protected static function bootHasTeamScope(): void
    {
        static::addGlobalScope('team', function (Builder $builder): void {
            if (! Auth::check()) {
                return;
            }

            $table = $builder->getModel()->getTable();
            $currentTeam = Auth::user()->currentTeam;

            if ($currentTeam) {
                $builder->where($table.'.team_id', $currentTeam->id);
            } else {
                $builder->whereRaw('1 = 0');
            }
        });
    }
}
