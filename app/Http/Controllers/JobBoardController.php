<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\HrGrant;
use App\Models\ProfessionalProfile;
use App\Models\Team;
use App\Models\User;
use App\Models\Vacancy;
use App\Notifications\NewApplicationNotification;
use App\Services\Recruitment\SkillMatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The public job board (R-32 Phase 4). Anyone may browse open roles; applying needs a
 * Dot.HR account (the InfoDot identity). Roles are read across businesses on purpose —
 * through Vacancy::openToPublic() only, so a draft, paused or closed role is a 404 to
 * everyone outside its business.
 */
class JobBoardController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'type' => ['nullable', Rule::in(array_keys(ProfessionalProfile::WORK_TYPES))],
            'where' => ['nullable', Rule::in(array_keys(ProfessionalProfile::ARRANGEMENTS))],
        ]);

        $vacancies = Vacancy::withoutTeamScope(fn () => Vacancy::query()
            ->openToPublic()
            ->with('team:id,name')
            ->when($filters['q'] ?? null, function (Builder $query, string $q) {
                // Wildcards are stripped, not escaped: LIKE escaping differs between SQLite
                // and PostgreSQL, and a search for "%" means nothing to a job seeker.
                $like = '%'.str_replace(['%', '_', '\\'], '', mb_strtolower(trim($q))).'%';
                $query->where(fn (Builder $w) => $w
                    ->whereRaw('LOWER(title) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(location) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(skills) LIKE ?', [$like]));
            })
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('work_type', $type))
            ->when($filters['where'] ?? null, fn (Builder $query, string $where) => $query->where('arrangement', $where))
            ->latest('published_at')
            ->paginate(20)
            ->withQueryString());

        $mySkills = $request->user()?->professionalProfile?->skills()->pluck('name') ?? collect();

        return view('jobs.index', [
            'vacancies' => $vacancies,
            'filters' => $filters,
            'mySkills' => $mySkills,
            'appliedIds' => $this->appliedIds($request),
        ]);
    }

    public function show(Request $request, int $vacancy): View
    {
        $role = $this->openRole($vacancy);
        $mySkills = $request->user()?->professionalProfile?->skills()->pluck('name') ?? collect();

        return view('jobs.show', [
            'vacancy' => $role,
            'match' => $request->user() ? SkillMatch::sentence($role->skills ?? [], $mySkills) : null,
            'existing' => $request->user()
                ? Application::withoutTeamScope(fn () => Application::query()->where('vacancy_id', $role->id)->where('user_id', $request->user()->id)->first())
                : null,
            'profile' => $request->user() ? ProfessionalProfile::query()->where('user_id', $request->user()->id)->first() : null,
        ]);
    }

    public function apply(Request $request, int $vacancy): RedirectResponse
    {
        $role = $this->openRole($vacancy);
        $user = $request->user();

        $questions = $role->screening_questions ?? [];
        $data = $request->validate([
            'cover_note' => ['nullable', 'string', 'max:4000'],
            'answers' => [count($questions) > 0 ? 'required' : 'nullable', 'array', 'size:'.count($questions)],
            'answers.*' => ['required', 'string', 'max:2000'],
            'share' => ['accepted'],
        ], [
            'share.accepted' => 'Applying shares your profile and email address with this business — tick the box to agree.',
            'answers.*.required' => 'Please answer every question.',
        ]);

        // One person, one application per role. The unique index is the guarantee; this
        // check is only for the friendly message on an ordinary second click.
        $existing = Application::withoutTeamScope(fn () => Application::query()->where('vacancy_id', $role->id)->where('user_id', $user->id)->first());

        if ($existing) {
            return redirect()->route('me.applications.show', $existing->id)->with('status', 'You have already applied for this role.');
        }

        try {
            $application = DB::transaction(function () use ($role, $user, $data) {
                $application = new Application([
                    'cover_note' => $data['cover_note'] ?? null,
                    'answers' => array_values($data['answers'] ?? []),
                ]);
                $application->forceFill([
                    'team_id' => $role->team_id,
                    'vacancy_id' => $role->id,
                    'user_id' => $user->id,
                    'stage' => 'new',
                    'stage_changed_at' => now(),
                ])->save();

                $application->record('applied', $user->id);

                return $application;
            });
        } catch (QueryException $e) {
            // Lost a race with a double submit: the other request created it.
            $existing = Application::withoutTeamScope(fn () => Application::query()->where('vacancy_id', $role->id)->where('user_id', $user->id)->firstOrFail());

            return redirect()->route('me.applications.show', $existing->id)->with('status', 'You have already applied for this role.');
        }

        ProfessionalProfile::forUser($user); // an applicant always has a profile to show

        Notification::send($this->recruitersOf($role->team), new NewApplicationNotification($application, $role->title));

        return redirect()->route('me.applications.show', $application->id)
            ->with('status', 'Application sent to '.$role->team->name.'. You can follow it here.');
    }

    private function openRole(int $id): Vacancy
    {
        /** @var Vacancy */
        return Vacancy::withoutTeamScope(fn () => Vacancy::query()->openToPublic()->with('team')->findOrFail($id));
    }

    /** @return array<int, int> */
    private function appliedIds(Request $request): array
    {
        if (! $request->user()) {
            return [];
        }

        return Application::withoutTeamScope(fn () => Application::query()->where('user_id', $request->user()->id)->pluck('vacancy_id')->all());
    }

    /** The team's HR administrators and holders of a recruiter grant — the people who act on it. */
    private function recruitersOf(Team $team)
    {
        $granted = HrGrant::query()->where('team_id', $team->id)->where('capability', HrGrant::RECRUITER)->pluck('user_id')->all();

        return $team->administrators()->merge(User::query()->whereIn('id', $granted)->get())->unique('id')->values();
    }
}
