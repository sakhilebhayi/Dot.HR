<?php

namespace App\Http\Controllers\Hiring;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ProfessionalProfile;
use App\Models\Vacancy;
use App\Services\Hr\Audit;
use App\Services\Intelligence\InternalTalent;
use App\Services\Recruitment\SkillMatch;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The business's roles (R-32 Phase 4): write, publish, pause, close, reopen, duplicate, and
 * the pipeline of one role. Writing needs the recruiter capability (or HR admin); hiring
 * managers may read. Every query runs under the team scope, so another business's role
 * answers 404.
 */
class VacancyController extends Controller
{
    public function index(Request $request): View
    {
        $access = $this->reviewer($request);

        $vacancies = Vacancy::query()
            ->withCount([
                'applications',
                'applications as open_applications_count' => fn ($q) => $q->whereIn('stage', Application::OPEN_STAGES),
                'applications as new_applications_count' => fn ($q) => $q->where('stage', 'new'),
            ])
            ->orderByRaw("CASE status WHEN 'published' THEN 0 WHEN 'draft' THEN 1 WHEN 'paused' THEN 2 ELSE 3 END")
            ->latest('updated_at')
            ->get();

        return view('hiring.index', ['vacancies' => $vacancies, 'canRecruit' => $access->canRecruit()]);
    }

    public function create(Request $request): View
    {
        $this->recruiter($request);

        return view('hiring.form', ['vacancy' => new Vacancy(['work_type' => 'full_time', 'arrangement' => 'on_site', 'pay_basis' => 'salary', 'openings' => 1, 'currency' => 'ZAR'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $access = $this->recruiter($request);

        $vacancy = new Vacancy($this->validated($request));
        $vacancy->forceFill([
            'team_id' => $access->team()->id,
            'created_by' => $request->user()->id,
            'status' => 'draft',
        ])->save();

        Audit::record('vacancy.created', $vacancy, ['title'], [], $vacancy->team_id);

        return redirect()->route('hiring.show', $vacancy)->with('status', 'Saved as a draft. Publish it when it reads right.');
    }

    public function edit(Request $request, Vacancy $vacancy): View
    {
        $this->recruiter($request);

        return view('hiring.form', ['vacancy' => $vacancy]);
    }

    public function update(Request $request, Vacancy $vacancy): RedirectResponse
    {
        $this->recruiter($request);

        $vacancy->fill($this->validated($request))->save();

        return redirect()->route('hiring.show', $vacancy)->with('status', 'Role updated.');
    }

    /** Publish, pause, close or reopen. Each is a plain state move, audited. */
    public function transition(Request $request, Vacancy $vacancy, string $to): RedirectResponse
    {
        $this->recruiter($request);

        $allowed = [
            'publish' => ['draft', 'paused'],
            'pause' => ['published'],
            'close' => ['published', 'paused', 'draft'],
            'reopen' => ['closed'],
        ];
        abort_unless(isset($allowed[$to]) && in_array($vacancy->status, $allowed[$to], true), 422);

        if ($to === 'publish' && $vacancy->closes_on !== null && $vacancy->closes_on->isBefore(today())) {
            return back()->with('error', 'The closing date has passed. Set a new one before publishing.');
        }

        $status = ['publish' => 'published', 'pause' => 'paused', 'close' => 'closed', 'reopen' => 'draft'][$to];
        $from = $vacancy->status;

        $vacancy->forceFill([
            'status' => $status,
            'published_at' => $status === 'published' ? ($vacancy->published_at ?? now()) : $vacancy->published_at,
        ])->save();

        Audit::record('vacancy.'.$to, $vacancy, ['status'], ['status' => ['from' => $from, 'to' => $status]], $vacancy->team_id);

        $messages = [
            'publish' => 'Published. It now appears on the Dot.HR job board.',
            'pause' => 'Paused. It is off the board and accepts no applications until you publish it again.',
            'close' => 'Closed. Applications already in the pipeline stay where they are.',
            'reopen' => 'Reopened as a draft. Check the dates, then publish.',
        ];

        return redirect()->route('hiring.show', $vacancy)->with('status', $messages[$to]);
    }

    public function duplicate(Request $request, Vacancy $vacancy): RedirectResponse
    {
        $access = $this->recruiter($request);

        $copy = $vacancy->replicate(['status', 'published_at', 'created_by', 'closes_on']);
        $copy->forceFill([
            'title' => $vacancy->title.' (copy)',
            'team_id' => $access->team()->id,
            'created_by' => $request->user()->id,
            'status' => 'draft',
            'published_at' => null,
            'closes_on' => null,
        ])->save();

        return redirect()->route('hiring.edit', $copy)->with('status', 'A draft copy. Edit and publish it when ready.');
    }

    /** One role's pipeline: every application in its stage, with the explainable skill match. */
    public function show(Request $request, Vacancy $vacancy, InternalTalent $talent): View
    {
        $access = $this->reviewer($request);

        $applications = $vacancy->applications()->with(['user.professionalProfile.skills'])->latest()->get();

        $rows = $applications->map(fn (Application $application) => [
            'application' => $application,
            'match' => SkillMatch::sentence(
                $vacancy->skills ?? [],
                $application->user->professionalProfile?->skills->pluck('name') ?? [],
            ),
        ]);

        return view('hiring.show', [
            'vacancy' => $vacancy,
            'byStage' => $rows->groupBy(fn ($row) => $row['application']->stage),
            'canRecruit' => $access->canRecruit(),
            'internal' => $talent->forVacancy($vacancy, $request->user()),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:140'],
            'description' => ['required', 'string', 'max:8000'],
            'responsibilities' => ['nullable', 'string', 'max:5000'],
            'requirements' => ['nullable', 'string', 'max:5000'],
            'skills' => ['nullable', 'string', 'max:1000'],
            'work_type' => ['required', Rule::in(array_keys(ProfessionalProfile::WORK_TYPES))],
            'arrangement' => ['required', Rule::in(array_keys(ProfessionalProfile::ARRANGEMENTS))],
            'location' => ['nullable', 'string', 'max:120', Rule::requiredIf(fn () => $request->input('arrangement') !== 'remote')],
            'pay_basis' => ['required', Rule::in(array_keys(Vacancy::PAY_BASES))],
            // A basis without an amount (commission, unpaid, not disclosed) ignores whatever
            // is left in the pay boxes instead of failing on it.
            'pay_min' => ['exclude_unless:pay_basis,salary,hourly,daily,project', 'required', 'numeric', 'min:0', 'max:9999999999'],
            'pay_max' => ['exclude_unless:pay_basis,salary,hourly,daily,project', 'nullable', 'numeric', 'gte:pay_min', 'max:9999999999'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'hours' => ['nullable', 'string', 'max:120'],
            'openings' => ['required', 'integer', 'min:1', 'max:500'],
            'starts_on' => ['nullable', 'date'],
            'closes_on' => ['nullable', 'date', 'after_or_equal:today'],
            'screening_questions' => ['nullable', 'string', 'max:2000'],
        ], [
            'location.required' => 'Say where the work is, unless it is fully remote.',
            'pay_min.required' => 'Give the pay, or choose "Not disclosed" as the basis.',
        ]);

        $data['skills'] = collect(explode(',', (string) ($data['skills'] ?? '')))
            ->map(fn ($s) => trim(preg_replace('/\s+/', ' ', $s) ?? ''))
            ->filter()
            ->unique(fn ($s) => SkillMatch::normalise($s))
            ->take(15)->values()->all();

        $data['screening_questions'] = collect(preg_split('/\R/', (string) ($data['screening_questions'] ?? '')) ?: [])
            ->map(fn ($q) => trim($q))
            ->filter()
            ->take(5)->values()->all();

        $data['currency'] = strtoupper($data['currency']);

        $data['pay_min'] ??= null;
        $data['pay_max'] ??= null;

        return $data;
    }

    private function recruiter(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->canRecruit(), 403);

        return $access;
    }

    private function reviewer(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->canReviewCandidates(), 403);

        return $access;
    }
}
