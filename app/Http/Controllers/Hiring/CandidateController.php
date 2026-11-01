<?php

namespace App\Http\Controllers\Hiring;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Vacancy;
use App\Support\Hr\HrAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Candidate search: everyone who has applied to this business, across its roles, found by
 * name, email, headline or skill (R-32 brief §5, candidate discovery). It searches only what
 * applying already shared with the business (the profile and email address); nobody who has not
 * applied here appears. Grouped by person, newest application first.
 */
class CandidateController extends Controller
{
    /** How far back the search looks; older ended applications are removed by retention anyway. */
    private const LIMIT = 1000;

    public function index(Request $request): View
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->canReviewCandidates(), 403);

        $q = trim((string) $request->query('q', ''));
        $state = in_array($request->query('state'), ['open', 'decided'], true) ? (string) $request->query('state') : 'all';
        $vacancyId = $request->integer('role') ?: null;

        $applications = Application::query()
            ->when($vacancyId, fn ($query) => $query->where('vacancy_id', $vacancyId))
            ->when($state === 'open', fn ($query) => $query->whereIn('stage', Application::OPEN_STAGES))
            ->when($state === 'decided', fn ($query) => $query->whereNotIn('stage', Application::OPEN_STAGES))
            ->with(['vacancy:id,team_id,title,status', 'user:id,name,email', 'user.professionalProfile:id,user_id,headline,location', 'user.professionalProfile.skills:id,professional_profile_id,name'])
            ->latest()->limit(self::LIMIT)->get();

        $terms = collect(preg_split('/\s+/', mb_strtolower($q)) ?: [])->filter(fn ($t) => mb_strlen($t) >= 2)->values();

        $people = $applications->groupBy('user_id')
            ->map(function (Collection $apps) use ($terms) {
                /** @var Application $first */
                $first = $apps->first();
                $profile = $first->user->professionalProfile;
                $skills = $profile?->skills->pluck('name')->all() ?? [];
                $haystack = mb_strtolower(implode(' ', [$first->user->name, $first->user->email, $profile?->headline, $profile?->location, ...$skills]));

                if ($terms->contains(fn ($t) => ! str_contains($haystack, $t))) {
                    return null;
                }

                return [
                    'user' => $first->user,
                    'headline' => $profile?->headline,
                    'location' => $profile?->location,
                    'skills' => $skills,
                    'matchedSkills' => $terms->isEmpty() ? [] : array_values(array_filter($skills, fn ($s) => $terms->contains(fn ($t) => str_contains(mb_strtolower($s), $t)))),
                    'applications' => $apps,
                ];
            })->filter()->values();

        return view('hiring.candidates', [
            'people' => $people,
            'q' => $q,
            'state' => $state,
            'roleId' => $vacancyId,
            'roles' => Vacancy::query()->orderByDesc('published_at')->get(['id', 'title', 'status']),
            'searched' => $q !== '' || $state !== 'all' || $vacancyId !== null,
        ]);
    }
}
