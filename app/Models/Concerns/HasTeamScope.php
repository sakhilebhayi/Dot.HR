<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Dot.HR is Jetstream-Teams-scoped, not single-user (see the policies and wiki.md).
 * Every model that owns a `team_id` column applies this trait, so a query against it
 * is scoped to the signed-in user's *current* team by default: a forgotten
 * where('team_id', ...) in a future controller cannot leak another team's rows,
 * because the model itself never returns them.
 *
 * It fails closed in every situation where the team cannot be established:
 *  - signed in, but no current team                          -> no rows
 *  - signed in, current team they do not belong to           -> no rows
 *  - nobody signed in (console, queue worker, scheduler, a
 *    request that skipped auth)                              -> no rows
 *
 * The only way past it is explicit: `Employee::withoutTeamScope(fn () => ...)`. That is
 * what the retention purge and the sign-in email match use, because they genuinely
 * work across teams with no user. It is not sticky (released in a finally block) and
 * applies to every team-scoped model inside the callback, so keep callbacks short and
 * filter by team_id yourself when you can.
 *
 * Mass assignment still sets team_id explicitly at create time; this scope only
 * governs reads.
 */
trait HasTeamScope
{
    protected static function bootHasTeamScope(): void
    {
        static::addGlobalScope('team', function (Builder $builder): void {
            if (TeamScopeBypass::active()) {
                return;
            }

            $table = $builder->getModel()->getTable();
            $user = Auth::user();
            $team = $user?->currentTeam;

            if ($user && $team && $user->belongsToTeam($team)) {
                $builder->where($table.'.team_id', $team->getKey());

                return;
            }

            $builder->whereRaw('1 = 0');
        });
    }

    /**
     * Run $callback with the team scope switched off, explicitly.
     */
    public static function withoutTeamScope(callable $callback): mixed
    {
        return TeamScopeBypass::run($callback);
    }
}
