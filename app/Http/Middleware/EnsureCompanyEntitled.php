<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Services\InfoDot\CompanyEntitlement;
use App\Support\Entitlement\Refusal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A person who is already signed in stops at a plain page once their current company no longer holds Dot.HR
 * (R-50). The request goes no further, so no record is read, shown or changed; nothing is deleted either: the
 * company's data is exactly as it was when access comes back.
 *
 * It runs on the signed-in area only. Anything a signed-out person can reach (the job board, public profiles,
 * the policies, health checks, the sign-in pages, the handoff receiver) carries no sign-in requirement and is
 * skipped, and so are signing out and switching workspace, which is how a person leaves this page.
 * HR_ENTITLEMENT_CHECK=false switches the whole check off.
 */
class EnsureCompanyEntitled
{
    /** Signed-in routes that must keep working: the ways out of the refusal page. */
    private const ALWAYS_OPEN = ['logout', 'current-team.update'];

    public function __construct(private CompanyEntitlement $entitlement) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->entitlement->enabled() || ! $this->guarded($request)) {
            return $next($request);
        }

        $user = $request->user() ?? Auth::guard('web')->user();
        $team = $user?->currentTeam;

        if (! $team instanceof Team) {
            return $next($request);
        }

        $answer = $this->entitlement->for($team);

        if ($answer['state'] === CompanyEntitlement::NOT_HELD) {
            return Refusal::respond($request, $answer['status']);
        }

        return $next($request);
    }

    /** Only routes that require a signed-in person, minus the ways out. */
    private function guarded(Request $request): bool
    {
        $route = $request->route();

        if ($route === null || in_array($route->getName(), self::ALWAYS_OPEN, true)) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && preg_match('/^(auth|.*\\\\Authenticate)(:|$)/', $middleware)) {
                return true;
            }
        }

        return false;
    }
}
