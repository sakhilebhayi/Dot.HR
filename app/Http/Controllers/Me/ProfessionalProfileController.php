<?php

namespace App\Http\Controllers\Me;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ProfessionalProfile;
use App\Models\ProfileEntry;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The person's own professional profile (R-32 Phase 3): the personal workspace's home,
 * the profile editor, skills, history entries, blocks, and the share link. Everything
 * here acts on the signed-in person's own profile only — there is no id in any route.
 */
class ProfessionalProfileController extends Controller
{
    public function home(Request $request): View
    {
        $user = $request->user();
        $profile = ProfessionalProfile::forUser($user)->load('skills', 'entries');

        // Workspaces the person can step into: every team they belong to, named. A personal
        // team counts too — a sole proprietor runs HR for their own business in it.
        $businesses = $user->allTeams()->values();

        // The person's own open applications, read through an explicit, user-filtered bypass.
        $applications = Application::withoutTeamScope(fn () => Application::query()
            ->where('user_id', $user->id)
            ->whereIn('stage', Application::OPEN_STAGES)
            ->with(['vacancy.team:id,name', 'interviews', 'offers'])
            ->latest()
            ->get());

        return view('me.home', [
            'profile' => $profile,
            'completeness' => $profile->completeness(),
            'businesses' => $businesses,
            'applications' => $applications,
        ]);
    }

    public function edit(Request $request): View
    {
        $profile = ProfessionalProfile::forUser($request->user())->load('skills', 'entries', 'blockedTeams');

        return view('me.profile', ['profile' => $profile]);
    }

    public function update(Request $request): RedirectResponse
    {
        $profile = ProfessionalProfile::forUser($request->user());

        $data = $request->validate([
            'headline' => ['nullable', 'string', 'max:140'],
            'about' => ['nullable', 'string', 'max:3000'],
            'location' => ['nullable', 'string', 'max:120'],
            'availability' => ['required', Rule::in(array_keys(ProfessionalProfile::AVAILABILITY))],
            'visibility' => ['required', Rule::in(array_keys(ProfessionalProfile::VISIBILITY))],
            'work_arrangements' => ['nullable', 'array'],
            'work_arrangements.*' => [Rule::in(array_keys(ProfessionalProfile::ARRANGEMENTS))],
            'work_types' => ['nullable', 'array', 'max:12'],
            'work_types.*' => [Rule::in(array_keys(ProfessionalProfile::WORK_TYPES))],
        ]);

        $profile->fill([
            'headline' => $data['headline'] ?? null,
            'about' => $data['about'] ?? null,
            'location' => $data['location'] ?? null,
            'availability' => $data['availability'],
            'visibility' => $data['visibility'],
            'work_arrangements' => array_values($data['work_arrangements'] ?? []),
            'work_types' => array_values($data['work_types'] ?? []),
        ])->save();

        return redirect()->route('me.profile')->with('status', 'Profile saved.');
    }

    public function addSkill(Request $request): RedirectResponse
    {
        $profile = ProfessionalProfile::forUser($request->user());

        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);
        $name = trim(preg_replace('/\s+/', ' ', $data['name']) ?? '');
        $normalized = Str::lower($name);

        if ($name === '' || $profile->skills()->where('normalized', $normalized)->exists()) {
            return redirect()->route('me.profile')->with('status', $name === '' ? 'Type a skill first.' : '"'.$name.'" is already on your profile.');
        }

        abort_if($profile->skills()->count() >= 40, 422, 'A profile holds up to 40 skills.');

        $profile->skills()->create([
            'name' => $name,
            'normalized' => $normalized,
            'position' => (int) $profile->skills()->max('position') + 1,
        ]);

        return redirect()->route('me.profile', ['#skills'])->with('status', '"'.$name.'" added.');
    }

    public function removeSkill(Request $request, int $skill): RedirectResponse
    {
        ProfessionalProfile::forUser($request->user())->skills()->whereKey($skill)->delete();

        return redirect()->route('me.profile', ['#skills']);
    }

    public function addEntry(Request $request): RedirectResponse
    {
        $profile = ProfessionalProfile::forUser($request->user());

        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(ProfileEntry::KINDS))],
            'title' => ['required', 'string', 'max:140'],
            'organisation' => ['nullable', 'string', 'max:140'],
            'started_on' => ['nullable', 'date', 'before_or_equal:today'],
            'ended_on' => ['nullable', 'date', 'after_or_equal:started_on'],
            'is_current' => ['nullable', 'boolean'],
            'expires_on' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        abort_if($profile->entries()->count() >= 60, 422, 'A profile holds up to 60 entries.');

        $isCurrent = (bool) ($data['is_current'] ?? false);

        $profile->entries()->create([
            'kind' => $data['kind'],
            'title' => $data['title'],
            'organisation' => $data['organisation'] ?? null,
            'started_on' => $data['started_on'] ?? null,
            'ended_on' => $isCurrent ? null : ($data['ended_on'] ?? null),
            'is_current' => $isCurrent,
            'expires_on' => $data['kind'] === 'certification' ? ($data['expires_on'] ?? null) : null,
            'description' => $data['description'] ?? null,
        ]);

        return redirect()->route('me.profile', ['#'.$data['kind']])->with('status', ProfileEntry::KINDS[$data['kind']].' updated.');
    }

    public function removeEntry(Request $request, int $entry): RedirectResponse
    {
        ProfessionalProfile::forUser($request->user())->entries()->whereKey($entry)->delete();

        return redirect()->route('me.profile');
    }

    /**
     * Block a business by its exact name. Deliberately no list to pick from: offering one
     * would hand every signed-in person the full roster of companies on Dot.HR.
     */
    public function block(Request $request): RedirectResponse
    {
        $profile = ProfessionalProfile::forUser($request->user());
        $data = $request->validate(['business' => ['required', 'string', 'max:255']]);

        $matches = Team::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower(trim($data['business']))])
            ->pluck('id');

        if ($matches->isEmpty()) {
            return redirect()->route('me.profile', ['#blocks'])
                ->with('error', 'No business on Dot.HR has exactly that name. Check the spelling as the business writes it.');
        }

        $profile->blockedTeams()->syncWithoutDetaching($matches->all());

        return redirect()->route('me.profile', ['#blocks'])->with('status', trim($data['business']).' can no longer see your profile.');
    }

    public function unblock(Request $request, int $team): RedirectResponse
    {
        ProfessionalProfile::forUser($request->user())->blockedTeams()->detach($team);

        return redirect()->route('me.profile', ['#blocks']);
    }

    /** The share link: what a viewer sees depends entirely on ProfessionalProfilePolicy. */
    public function show(Request $request, string $handle): View
    {
        $profile = ProfessionalProfile::query()->where('handle', $handle)->firstOrFail();

        // A profile the viewer may not see is indistinguishable from one that does not exist.
        abort_unless(Gate::forUser($request->user())->allows('view', $profile), 404);

        return view('me.public', ['profile' => $profile->load('skills', 'entries', 'user')]);
    }
}
