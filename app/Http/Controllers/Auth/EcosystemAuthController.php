<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Ecosystem\ProvisionUserFromInfoDot;
use App\Actions\Ecosystem\SyncTeamsFromInfoDot;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCompanyEntitled;
use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\IdentityClient;
use App\Services\InfoDot\InfoDotUnavailable;
use App\Support\Entitlement\Refusal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class EcosystemAuthController extends Controller
{
    /** A GET (an old link with the token in the URL) is not honoured: the token is never read. */
    public function legacy(): RedirectResponse
    {
        return redirect()->to('/login');
    }

    /**
     * Sign-in from InfoDot. The token arrives in the POST body of a form InfoDot's launch page posts here, so it
     * is never in a URL (address bar, history, access logs, Referer). A refused sign-in lands on the plain login
     * page: the credential is not shown, echoed, flashed or logged. A GET with ?token= is no longer honoured
     * (the route sends it to the login page), and a token in the query string of a POST is ignored.
     */
    public function handle(Request $request): Response
    {
        if ($request->isMethod('post') && ! $this->postedFromInfoDot($request)) {
            return $this->refused();
        }

        try {
            return app()->call([$this, 'redeem'], ['request' => $request]);
        } catch (HttpExceptionInterface $e) {
            return $this->refused($e->getStatusCode() === 503);
        }
    }

    /**
     * Login CSRF: a form on another site could sign a visitor in as someone else (the attacker's own valid
     * token). A browser names the page that posted a cross-site form in Origin, so only InfoDot's launch page,
     * on this app's parent domain (tasks.infodot.co.za -> infodot.co.za), is accepted.
     */
    private function postedFromInfoDot(Request $request): bool
    {
        $origin = (string) $request->headers->get('Origin', '');
        $host = $origin !== '' && $origin !== 'null'
            ? parse_url($origin, PHP_URL_HOST)
            : parse_url((string) $request->headers->get('Referer', ''), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return in_array($request->headers->get('Sec-Fetch-Site'), ['same-origin', 'same-site'], true);
        }

        $host = strtolower($host);

        // When this app knows InfoDot's address, exactly that origin is accepted, not a sibling subdomain.
        $infodot = parse_url((string) config('ecosystem.infodot.redeem_url', ''), PHP_URL_HOST);

        if (is_string($infodot) && $infodot !== '') {
            return $host === strtolower($infodot);
        }

        // An app without that setting falls back to its own parent domain.
        $self = strtolower($request->getHost());
        $parent = str_contains($self, '.') ? substr($self, strpos($self, '.') + 1) : $self;

        return $host === $self || $host === $parent || str_ends_with($host, '.'.$parent);
    }

    private function refused(bool $unavailable = false): RedirectResponse
    {
        return redirect('/login', 303)->with('status', $unavailable
            ? 'Sign-in through InfoDot is unavailable right now. Please try again in a moment.'
            : 'We could not sign you in through InfoDot. Please open this app again from InfoDot.');
    }

    public function redeem(Request $request, IdentityClient $infoDot, ProvisionUserFromInfoDot $provision, SyncTeamsFromInfoDot $syncTeams): Response
    {
        $token = $request->post('token');

        // A missing, empty or array token (?token[]=x) is a clean 403, never a 500.
        abort_unless(is_string($token) && $token !== '', 403);

        $user = $this->userFromLocalToken($token);
        $personal = null;

        // Production gives each Dot app its own database, so InfoDot's token is not
        // in ours: ask InfoDot to prove it.
        if (! $user && $infoDot->enabled()) {
            try {
                $identity = $infoDot->redeem($token);
            } catch (InfoDotUnavailable) {
                abort(503, 'Sign-in through InfoDot is unavailable right now. Please try again in a moment.');
            }

            // R-50: InfoDot marked every company, and none of them holds this platform. Nobody is signed in and
            // nothing is created or changed here; the person gets the plain page, not the login form.
            if ($identity && ($reason = $this->nothingEntitled($identity)) !== null) {
                return Refusal::respond($request, $reason);
            }

            $user = $identity ? $provision($identity) : null;

            if ($user && $identity) {
                $syncTeams($user, $identity);
                $personal = $this->personalAnswer($identity);
            }
        }

        abort_if(! $user, 403);

        $this->settleCurrentTeam($user);

        Auth::login($user);

        // A new session id at the moment of login, so a session id planted before it is useless.
        $request->session()->regenerate();

        // R-50: what InfoDot said about the person's personal workspace for this platform, kept with the sign-in so
        // the local personal workspace is not a way round the check. No answer (an older InfoDot): remember nothing.
        if ($personal === null) {
            $request->session()->forget(EnsureCompanyEntitled::PERSONAL_ANSWER);
        } else {
            $request->session()->put(EnsureCompanyEntitled::PERSONAL_ANSWER, $personal);
        }

        return redirect()->to($this->safeRedirect($request->post('redirect')) ?? route('dashboard'));
    }

    /**
     * The reason to show when InfoDot marked the person's companies and none is entitled; null when at least one
     * is, or when the answer carries no `entitled` key at all (an older InfoDot: behave exactly as before).
     *
     * @param  array<string, mixed>  $identity
     */
    private function nothingEntitled(array $identity): ?string
    {
        $teams = collect($identity['teams'] ?? [])->filter(fn ($team) => is_array($team));
        $marked = $teams->filter(fn ($team) => array_key_exists('entitled', $team));

        if ($marked->isEmpty() || $teams->contains(fn ($team) => SyncTeamsFromInfoDot::admits($team))) {
            return null;
        }

        // Word it from a company's reason when there is one; a personal workspace's reason only as a last resort.
        $reason = $marked->sortBy(fn ($team) => ($team['personal'] ?? false) ? 1 : 0)->first()['access'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : 'not_activated';
    }

    /**
     * InfoDot's `entitled` for the person's personal workspace: true only when exactly true, null when the answer
     * lists no personal workspace or carries no such key.
     *
     * @param  array<string, mixed>  $identity
     */
    private function personalAnswer(array $identity): ?bool
    {
        $personal = collect($identity['teams'] ?? [])->first(fn ($team) => is_array($team) && ($team['personal'] ?? false) === true);

        return is_array($personal) && array_key_exists('entitled', $personal) ? $personal['entitled'] === true : null;
    }

    /**
     * Every query here is scoped to the person's current team (HasTeamScope), and that scope
     * trusts it. So make sure it is a team they really belong to: otherwise fall back to their
     * personal team, or to none (the scope then fails closed and shows nothing).
     */
    private function settleCurrentTeam(User $user): void
    {
        $user->unsetRelation('teams')->unsetRelation('ownedTeams')->unsetRelation('currentTeam');

        $current = $user->current_team_id ? Team::find($user->current_team_id) : null;

        if ($current && $user->belongsToTeam($current)) {
            return;
        }

        $fallback = $user->personalTeam();

        if ($fallback) {
            $user->switchTeam($fallback);

            return;
        }

        if ($user->current_team_id !== null) {
            $user->forceFill(['current_team_id' => null])->save();
        }
    }

    /**
     * A deep link names a page in this app. Only a plain path is honoured: one leading slash,
     * no "//" (protocol-relative), no backslash, no ".." and no control characters, also after
     * decoding. Anything else falls back to the dashboard, so this can never be an open redirect.
     */
    private function safeRedirect(mixed $redirect): ?string
    {
        if (! is_string($redirect) || $redirect === '' || strlen($redirect) > 2048) {
            return null;
        }

        foreach ([$redirect, rawurldecode($redirect), rawurldecode(rawurldecode($redirect))] as $form) {
            if (
                $form[0] !== '/'
                || str_starts_with($form, '//')
                || str_contains($form, '\\')
                || str_contains($form, '..')
                || str_contains($form, '//')
                || preg_match('/[\x00-\x1f\x7f]/', $form)
            ) {
                return null;
            }
        }

        return $redirect;
    }

    /** A token minted in a database we share (the original design): found, checked, spent. */
    private function userFromLocalToken(string $token): ?User
    {
        $accessToken = PersonalAccessToken::findToken($token);

        if (
            ! $accessToken
            || ! $accessToken->can('ecosystem:read')
            || ($accessToken->expires_at && $accessToken->expires_at->isPast())
        ) {
            return null;
        }

        $user = $accessToken->tokenable;
        $accessToken->delete();

        return $user instanceof User ? $user : null;
    }
}
