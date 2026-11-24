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
 * R-61: when InfoDot has switched Dot.HR off (maintenance, unavailable) signed-in requests get a 503 with InfoDot's message,
 * ahead of the company check. A person's own POPIA data-subject access routes are never blocked or paywalled.
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

    private const ALWAYS_OPEN = [
        'logout', 'current-team.update',
        // A person's own POPIA data-subject access is never paywalled or switched off (R-61).
        'employees.personal-data', 'my-work.data', 'me.data',
    ];

    /** The word refusal() gives when InfoDot has switched Dot.HR off (R-61); it is not a status InfoDot sends. */
    public const UNAVAILABLE = 'platform_unavailable';

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
        if ($word === self::UNAVAILABLE) {
            return $this->unavailable($request);
        }

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

        // R-61: a platform InfoDot has switched off is off for everyone, whatever they hold. Checked before what the company holds.
        if ($answer !== null && ($answer['available'] ?? true) === false) {
            return self::UNAVAILABLE;
        }

        return $answer !== null && $answer['state'] === CompanyEntitlement::NOT_HELD ? ($answer['status'] ?? 'not_activated') : null;
    }

    /** 503 with the message and nothing else: no record is read, changed, deleted, and the person is not signed out. */
    private function unavailable(Request $request): Response
    {
        $team = $request->user()?->currentTeam;
        $message = $team instanceof Team && filled($team->infodot_team_uuid) ? ($this->entitlement->remembered($team)['message'] ?? null) : null;

        if ($request->expectsJson() || $this->isLivewireUpdate($request)) {
            return response()->json(['message' => $message ?? 'Dot.HR is temporarily unavailable.', 'reason' => self::UNAVAILABLE], 503)
                ->header('Retry-After', '60')->header('Cache-Control', 'no-store, private');
        }

        return response()->view('errors.platform-unavailable', ['message' => $message, 'signedIn' => true], 503)
            ->header('Cache-Control', 'no-store, private')->header('Retry-After', '60');
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
