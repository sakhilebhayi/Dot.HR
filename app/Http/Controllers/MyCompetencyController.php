<?php

namespace App\Http\Controllers;

use App\Models\Competency;
use App\Models\Employee;
use App\Services\Hr\Competencies;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** A person's own competencies: what their position expects, where they place themselves, what was agreed. */
class MyCompetencyController extends Controller
{
    public function __construct(private Competencies $competencies) {}

    public function index(Request $request): View
    {
        $employee = $this->me($request);

        return view('competencies.person', ['employee' => $employee->load('position'), 'rows' => $this->competencies->profile($employee), 'admin' => false]);
    }

    public function assess(Request $request, Competency $competency): RedirectResponse
    {
        $employee = $this->me($request);
        $data = $request->validate(['level' => ['required', Rule::in(['1', '2', '3'])], 'evidence' => ['nullable', 'string', 'max:2000']]);
        $this->competencies->selfAssess($employee, $competency, (int) $data['level'], $data['evidence'] ?? null);

        return back()->with('status', 'Saved. HR sees where you place yourself and your evidence.');
    }

    private function me(Request $request): Employee
    {
        $employee = HrAccess::current($request->user())?->employee();
        abort_if($employee === null, 404);

        return $employee;
    }
}
