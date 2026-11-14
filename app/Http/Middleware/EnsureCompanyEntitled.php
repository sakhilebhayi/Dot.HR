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
 * A personal workspace (or a team InfoDot does not know) follows what InfoDot said about the person's personal
 * workspace at sign-in, remembered in the session; nothing in it is deleted or altered either.
 *
 * It runs on the signed-in area only. Anything a signed-out person can reach (the job board, public profiles,
 * the policies, health checks, the sign-in pages, the handoff receiver) carries no sign-in requirement and is
 * skipped, and so are signing out and switching workspace, which is how a person leaves this page.
 * HR_ENTITLEMENT_CHECK=false switches the whole check off.
 */
class EnsureCompanyEntitled
{
    /** Signed-in routes that must keep working: the ways out of the refusal page. */
    /** Session key: InfoDot's answer about the person's personal workspace, remembered at sign-in. */
    public const PERSONAL_ANSWER = 'entitlement_personal';

    private const ALWAYS_OPEN = ['logout', 'current-team.update'];

    public function __construct(private CompanyEntitlement $entitlement) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->entitlement->enabled() || ! $this->guarded($request)) {
            return $next($request);
        }

        $word = $this->refusal($request);

        if ($word === null) {
            return $next($request);
        }

        // Livewire throws away any non-redirect response a middleware returns on its update endpoint, so a refused
        // update must abort: the component does not run and nothing is returned.
        if ($this->isLivewireUpdate($request)) {
            abort(403);
        }

        return Refusal::respond($request, $word);
    }

    /**
     * The reason this request is refused (InfoDot's status word, or `company_required`), or null to carry on.
     * With $cachedOnly the read endpoint is never called: only an answer already remembered counts, which is what
     * the notification bell uses to stay out of the way on public pages.
     */
    public function refusal(Request $request, bool $cachedOnly = false): ?string
    {
        $user = $request->user() ?? Auth::guard('web')->user();

        if ($user === null || ! $this->entitlement->enabled()) {
            return null;
        }

        $team = $user->currentTeam;

        // A personal workspace, a team InfoDot does not know, or no team at all: InfoDot is never asked about it.
        // Individuals hold the free core only (owner decision, 2026-11-14), so when InfoDot said at sign-in that
        // the personal workspace does not hold this platform, the person needs a company that does. Anything else
        // (it said yes, or nothing was remembered: an older InfoDot or an older session) carries on as before.
        if (! $team instanceof Team || $team->personal_team || blank($team->infodot_team_uuid)) {
            $remembered = $request->hasSession() ? $request->session()->get(self::PERSONAL_ANSWER) : null;

            return $remembered === false ? 'company_required' : null;
        }

        $answer = $cachedOnly ? $this->entitlement->remembered($team) : $this->entitlement->for($team);

        return $answer !== null && $answer['state'] === CompanyEntitlement::NOT_HELD ? ($answer['status'] ?? 'not_activated') : null;
    }

    private function isLivewireUpdate(Request $request): bool
    {
        return $request->route()?->getName() === 'default.livewire.update' || $request->is('livewire/update', 'livewire-*/update');
    }

    /** Only routes that require a signed-in person, minus the ways out. */
    private function guarded(Request $request): bool
    {
        $route = $request->route();

        // The Livewire update endpoint carries no sign-in middleware of its own, but what it runs is the signed-in area.
        if ($this->isLivewireUpdate($request)) {
            return true;
        }

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
