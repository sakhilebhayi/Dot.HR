<?php

namespace App\Http\Controllers;

use App\Models\Competency;
use App\Models\Employee;
use App\Models\Position;
use App\Models\PositionCompetency;
use App\Services\Hr\Competencies;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The competency framework and matrix (brief §10), and each person's place in it. HR
 * administrators; the person's own view is under My work.
 */
class CompetencyController extends Controller
{
    public function __construct(private Competencies $competencies) {}

    public function index(Request $request): View
    {
        $this->admin($request);
        $editing = $request->filled('edit') ? Competency::query()->find($request->integer('edit')) : null;

        return view('competencies.index', [
            'competencies' => Competency::query()->live()->orderBy('area')->orderBy('name')->get(),
            'positions' => Position::query()->orderBy('title')->get(),
            'matrix' => PositionCompetency::query()->get()->groupBy('position_id')->map(fn ($cells) => $cells->pluck('expected_level', 'competency_id')),
            'editing' => $editing,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $access = $this->admin($request);
        $competency = $this->competencies->save($access->team(), $this->validated($request));

        return redirect()->to(route('competencies.index').'#matrix')->with('status', $competency->name.' added. Set which positions need it in the matrix.');
    }

    public function update(Request $request, Competency $competency): RedirectResponse
    {
        $access = $this->admin($request);
        $this->competencies->save($access->team(), $this->validated($request), $competency);

        return redirect()->route('competencies.index')->with('status', $competency->name.' saved.');
    }

    public function retire(Request $request, Competency $competency): RedirectResponse
    {
        $this->admin($request);
        $this->competencies->retire($competency);

        return back()->with('status', $competency->name.' retired. Past assessments stay on record.');
    }

    public function matrix(Request $request, Position $position): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate(['levels' => ['array'], 'levels.*' => ['nullable', Rule::in(['', '1', '2', '3'])]]);
        $this->competencies->setMatrixRow($position, $data['levels'] ?? []);

        return redirect()->to(route('competencies.index').'#matrix')->with('status', 'Saved what '.$position->title.' needs.');
    }

    public function person(Request $request, Employee $employee): View
    {
        $this->admin($request);

        return view('competencies.person', ['employee' => $employee->load('position'), 'rows' => $this->competencies->profile($employee), 'admin' => true]);
    }

    public function agree(Request $request, Employee $employee, Competency $competency): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate(['level' => ['required', Rule::in(['1', '2', '3'])], 'note' => ['nullable', 'string', 'max:2000']]);
        $this->competencies->agree($employee, $competency, (int) $data['level'], $data['note'] ?? null, $request->user());

        return back()->with('status', $competency->name.': '.Competency::LEVELS[(int) $data['level']].' agreed. '.$employee->first_name.' has been told.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'area' => ['nullable', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'level_1' => ['required', 'string', 'max:1000'],
            'level_2' => ['required', 'string', 'max:1000'],
            'level_3' => ['required', 'string', 'max:1000'],
        ]);
    }

    private function admin(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->isAdmin(), 403);

        return $access;
    }
}
