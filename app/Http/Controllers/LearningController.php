<?php

namespace App\Http\Controllers;

use App\Models\Competency;
use App\Models\Employee;
use App\Models\LearningPath;
use App\Models\LearningPathEnrolment;
use App\Models\LearningPathStep;
use App\Models\Position;
use App\Services\Hr\LearningPaths;
use App\Support\Hr\HrAccess;
use App\Support\Hr\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Learning paths (brief §10). HR administrators design paths; HR administrators and supervisors
 * put people on them, book their courses and confirm steps. The person's own view is under My
 * work. Progress is steps done; people are listed by name, never ordered by how far they got.
 */
class LearningController extends Controller
{
    public function __construct(private LearningPaths $paths) {}

    public function index(Request $request): View
    {
        $access = $this->manager($request);
        $paths = LearningPath::query()->live()->with('position')->withCount('steps')->orderBy('name')->get();
        $enrolments = LearningPathEnrolment::query()->whereIn('learning_path_id', $paths->pluck('id'))->whereNull('dropped_at')->get(['id', 'learning_path_id', 'completed_at']);

        return view('learning.index', [
            'paths' => $paths,
            'active' => $enrolments->whereNull('completed_at')->countBy('learning_path_id'),
            'finished' => $enrolments->whereNotNull('completed_at')->countBy('learning_path_id'),
            'positions' => Position::query()->orderBy('title')->get(['id', 'title']),
            'isAdmin' => $access->isAdmin(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $access = $this->admin($request);
        $path = $this->paths->save($access->team(), $this->validatedPath($request), $request->user());

        return redirect()->route('learning.paths.show', $path)->with('status', $path->name.' made. Add its steps below.');
    }

    public function update(Request $request, LearningPath $path): RedirectResponse
    {
        $access = $this->admin($request);
        $this->paths->save($access->team(), $this->validatedPath($request), $request->user(), $path);

        return back()->with('status', $path->name.' saved.');
    }

    public function retire(Request $request, LearningPath $path): RedirectResponse
    {
        $this->admin($request);
        $this->paths->retire($path);

        return redirect()->route('learning.index')->with('status', $path->name.' retired. What people did on it stays on their record.');
    }

    public function show(Request $request, LearningPath $path): View
    {
        $access = $this->manager($request);
        abort_if($path->retired_at !== null, 404);
        $enrolments = LearningPathEnrolment::query()->where('learning_path_id', $path->id)->whereNull('dropped_at')
            ->with('employee')->get()->sortBy(fn (LearningPathEnrolment $e) => $e->employee->fullName())->values();
        $onIt = $enrolments->pluck('employee_id');

        return view('learning.show', [
            'path' => $path->load('position'),
            'steps' => LearningPathStep::query()->where('learning_path_id', $path->id)->whereNull('retired_at')->with('competency')->orderBy('position')->orderBy('id')->get(),
            'active' => $enrolments->filter->isActive()->values(),
            'finished' => $enrolments->whereNotNull('completed_at')->values(),
            'counts' => $this->paths->counts($enrolments),
            'people' => Employee::query()->where('status', '!=', 'terminated')->whereNotIn('id', $onIt)->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'inPosition' => $path->position_id ? Employee::query()->where('position_id', $path->position_id)->where('status', '!=', 'terminated')->whereNotIn('id', $onIt)->count() : 0,
            'positions' => Position::query()->orderBy('title')->get(['id', 'title']),
            'competencies' => Competency::query()->live()->orderBy('name')->get(['id', 'name']),
            'editingStep' => $request->filled('step') ? LearningPathStep::query()->where('learning_path_id', $path->id)->whereNull('retired_at')->find($request->integer('step')) : null,
            'isAdmin' => $access->isAdmin(),
        ]);
    }

    public function storeStep(Request $request, LearningPath $path): RedirectResponse
    {
        $this->admin($request);
        abort_if($path->retired_at !== null, 404);
        $step = $this->paths->saveStep($path, $this->validatedStep($request));

        return redirect()->to(route('learning.paths.show', $path).'#steps')->with('status', '"'.$step->title.'" added.');
    }

    public function updateStep(Request $request, LearningPathStep $step): RedirectResponse
    {
        $this->admin($request);
        $this->paths->saveStep($step->path, $this->validatedStep($request), $step);

        return redirect()->to(route('learning.paths.show', $step->learning_path_id).'#steps')->with('status', '"'.$step->title.'" saved.');
    }

    public function moveStep(Request $request, LearningPathStep $step, string $direction): RedirectResponse
    {
        $this->admin($request);
        $this->paths->moveStep($step, $direction);

        return redirect()->to(route('learning.paths.show', $step->learning_path_id).'#steps');
    }

    public function retireStep(Request $request, LearningPathStep $step): RedirectResponse
    {
        $this->admin($request);
        $this->paths->retireStep($step);

        return redirect()->to(route('learning.paths.show', $step->learning_path_id).'#steps')->with('status', '"'.$step->title.'" retired. Anyone who did it keeps it on record.');
    }

    public function enrol(Request $request, LearningPath $path): RedirectResponse
    {
        $this->manager($request);
        $data = $request->validate(['employee_ids' => ['required', 'array', 'min:1', 'max:200'], 'employee_ids.*' => ['integer']]);
        $people = Employee::query()->whereIn('id', $data['employee_ids'])->where('status', '!=', 'terminated')->get();
        abort_if($people->count() !== count(array_unique($data['employee_ids'])), 422, 'Choose people in this business.');
        $people->each(fn (Employee $e) => $this->paths->enrol($path, $e, $request->user()));

        return redirect()->to(route('learning.paths.show', $path).'#people')
            ->with('status', $people->count() === 1 ? $people->first()->first_name.' is on '.$path->name.'.' : $people->count().' people are on '.$path->name.'.');
    }

    public function enrolPosition(Request $request, LearningPath $path): RedirectResponse
    {
        $this->manager($request);
        $n = $this->paths->enrolPosition($path, $request->user());

        return redirect()->to(route('learning.paths.show', $path).'#people')->with('status', $n === 0 ? 'Everyone in the position is already on it.' : $n.' '.($n === 1 ? 'person' : 'people').' put on '.$path->name.'.');
    }

    public function enrolment(Request $request, LearningPathEnrolment $enrolment): View
    {
        $this->manager($request);

        return view('learning.enrolment', ['enrolment' => $enrolment->load(['path', 'employee']), 'rows' => $this->paths->rows($enrolment), 'asHr' => true]);
    }

    public function done(Request $request, LearningPathEnrolment $enrolment, LearningPathStep $step): RedirectResponse
    {
        $this->manager($request);
        $data = $request->validate(['done_on' => ['nullable', 'date', 'before_or_equal:'.LocalTime::today()->toDateString(), 'after_or_equal:'.$enrolment->started_on->toDateString()]]);
        $this->paths->markDone($enrolment, $step, $request->user(), isset($data['done_on']) ? Carbon::parse($data['done_on']) : null);

        return back()->with('status', '"'.$step->title.'" marked done.');
    }

    public function undo(Request $request, LearningPathEnrolment $enrolment, LearningPathStep $step): RedirectResponse
    {
        $this->manager($request);
        $this->paths->undo($enrolment, $step, $request->user(), asHr: true);

        return back()->with('status', '"'.$step->title.'" is open again.');
    }

    public function book(Request $request, LearningPathEnrolment $enrolment, LearningPathStep $step): RedirectResponse
    {
        $this->manager($request);
        $data = $request->validate(['starts_on' => ['nullable', 'date']]);
        $record = $this->paths->bookCourse($enrolment, $step, $request->user(), $data['starts_on'] ?? null);

        return back()->with('status', $record->title.' booked. It is in Training, where its completion is recorded.');
    }

    public function drop(Request $request, LearningPathEnrolment $enrolment): RedirectResponse
    {
        $this->manager($request);
        $this->paths->drop($enrolment);

        return redirect()->route('learning.paths.show', $enrolment->learning_path_id)->with('status', 'Taken off '.$enrolment->path->name.'. What was done stays on record.');
    }

    /** @return array<string, mixed> */
    private function validatedPath(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'position_id' => ['nullable', 'integer', Rule::exists('positions', 'id')->where('team_id', HrAccess::current($request->user())?->team()->id)],
            'self_enrol' => ['nullable', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedStep(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'kind' => ['required', Rule::in(array_keys(LearningPathStep::KINDS))],
            'detail' => ['nullable', 'string', 'max:500'],
            'provider' => ['nullable', 'string', 'max:140'],
            'certification_name' => ['nullable', 'string', 'max:140'],
            'competency_id' => ['nullable', 'integer'],
            'within_days' => ['nullable', 'integer', 'min:1', 'max:730'],
        ]);
    }

    private function manager(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->canManageWorkforce(), 403);

        return $access;
    }

    private function admin(Request $request): HrAccess
    {
        $access = $this->manager($request);
        abort_unless($access->isAdmin(), 403);

        return $access;
    }
}
