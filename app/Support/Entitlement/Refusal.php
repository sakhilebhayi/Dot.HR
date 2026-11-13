<?php

namespace App\Support\Entitlement;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * The one page a person sees when their company does not hold Dot.HR (R-50): at sign-in, and during a session.
 * It is worded from InfoDot's own status word and carries nothing from this app's records: no employee, no figure,
 * no company other than the names of the person's own workspaces (so they can switch).
 */
class Refusal
{
    /**
     * @param  string|null  $word  InfoDot's company status (read endpoint) or refusal reason (redeem)
     * @return array{title: string, message: string}
     */
    public static function wording(?string $word): array
    {
        return match ($word) {
            'suspended' => [
                'title' => 'Dot.HR is suspended for your company',
                'message' => 'Your company\'s access to Dot.HR has been suspended. Everything recorded here is kept as it was. Whoever manages your company in InfoDot can see why and what to do next.',
            ],
            'payment_required' => [
                'title' => 'Dot.HR needs a payment from your company',
                'message' => 'A payment is needed before your company can use Dot.HR again. Everything recorded here is kept as it was. Whoever manages your company in InfoDot can settle it there.',
            ],
            'subscription_expired', 'expired', 'trial_ended', 'cancelled' => [
                'title' => 'Dot.HR has ended for your company',
                'message' => 'Your company\'s time on Dot.HR has ended. Everything recorded here is kept as it was. Whoever manages your company in InfoDot can switch it on again.',
            ],
            'not_permitted_for_you' => [
                'title' => 'Your company has not given you Dot.HR',
                'message' => 'Your company uses Dot.HR, but only for the people it has chosen, and you are not one of them yet. Whoever manages your company in InfoDot can add you.',
            ],
            'no_personal_access', 'company_required' => [
                'title' => 'Dot.HR needs a company',
                'message' => 'Dot.HR is switched on for a company, not for a personal workspace. You can see which of your companies has it in InfoDot.',
            ],
            default => [
                'title' => 'Dot.HR is not switched on for your company',
                'message' => 'Your company does not have Dot.HR at the moment. Whoever manages your company in InfoDot can switch it on there.',
            ],
        };
    }

    /** Where a person goes to see, or change, what their company holds. */
    public static function infodotUrl(): string
    {
        return rtrim((string) config('ecosystem.platforms.infodot.url', 'https://infodot.co.za'), '/')
            .'/platforms/'.rawurlencode((string) config('ecosystem.infodot.platform', 'hr'));
    }

    public static function respond(Request $request, ?string $word): BaseResponse
    {
        $wording = self::wording($word);

        if ($request->expectsJson()) {
            return response()->json(['message' => $wording['title'].'.', 'infodot_url' => self::infodotUrl()], 403)
                ->header('Cache-Control', 'no-store, private');
        }

        $user = $request->user();
        $current = $user?->current_team_id;

        return new Response(view('errors.not-entitled', [
            'wording' => $wording,
            'infodotUrl' => self::infodotUrl(),
            'signedIn' => $user !== null,
            // The person's own other workspaces, by name only.
            'workspaces' => $user ? $user->allTeams()->reject(fn ($team) => $team->id === $current)->values() : collect(),
        ]), 403, ['Cache-Control' => 'no-store, private']);
    }
}
