<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LearningPath;
use App\Models\LearningPathEnrolment;
use App\Models\LearningPathStep;
use App\Services\Hr\LearningPaths;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** A person's own learning paths: the ones they are on, the open ones they may start, and their steps. */
class MyLearningController extends Controller
{
    public function __construct(private LearningPaths $paths) {}

    public function index(Request $request): View
    {
        $employee = $this->me($request);
        $mine = LearningPathEnrolment::query()->where('employee_id', $employee->id)->whereNull('dropped_at')
            ->whereHas('path', fn ($q) => $q->whereNull('retired_at'))->with('path')->latest('started_on')->get();

        return view('learning.mine', [
            'employee' => $employee,
            'mine' => $mine,
            'counts' => $this->paths->counts($mine),
            'open' => LearningPath::query()->live()->where('self_enrol', true)->whereNotIn('id', $mine->pluck('learning_path_id'))->withCount('steps')->orderBy('name')->get(),
        ]);
    }

    public function show(Request $request, LearningPathEnrolment $enrolment): View
    {
        $this->mine($request, $enrolment);

        return view('learning.enrolment', ['enrolment' => $enrolment->load(['path', 'employee']), 'rows' => $this->paths->rows($enrolment), 'asHr' => false]);
    }

    public function join(Request $request, LearningPath $path): RedirectResponse
    {
        $employee = $this->me($request);
        abort_unless($path->self_enrol && $path->retired_at === null, 404);
        $enrolment = $this->paths->enrol($path, $employee, $request->user());

        return redirect()->route('my-work.learning.show', $enrolment)->with('status', 'You started '.$path->name.'.');
    }

    public function done(Request $request, LearningPathEnrolment $enrolment, LearningPathStep $step): RedirectResponse
    {
        $this->mine($request, $enrolment);
        $this->paths->markDone($enrolment, $step, $request->user());

        return back()->with('status', '"'.$step->title.'" done.');
    }

    public function undo(Request $request, LearningPathEnrolment $enrolment, LearningPathStep $step): RedirectResponse
    {
        $this->mine($request, $enrolment);
        $this->paths->undo($enrolment, $step, $request->user(), asHr: false);

        return back()->with('status', '"'.$step->title.'" is open again.');
    }

    public function ask(Request $request, LearningPathEnrolment $enrolment, LearningPathStep $step): RedirectResponse
    {
        $this->mine($request, $enrolment);
        $this->paths->askForCourse($enrolment, $step, $request->user());

        return back()->with('status', 'Asked for '.$step->title.'. You will hear when it is decided.');
    }

    private function mine(Request $request, LearningPathEnrolment $enrolment): void
    {
        abort_unless($enrolment->employee_id === $this->me($request)->id, 404);
    }

    private function me(Request $request): Employee
    {
        $employee = HrAccess::current($request->user())?->employee();
        abort_if($employee === null, 404);

        return $employee;
    }
}
