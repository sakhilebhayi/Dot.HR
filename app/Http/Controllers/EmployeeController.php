<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD for Employee records, scoped to the current team throughout. Every
 * action that loads a specific Employee by ID authorizes via EmployeePolicy
 * (never the query alone) — see EmployeePolicy's docblock for why.
 */
class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Employee::class);

        // HasTeamScope's global scope already restricts this to the current
        // team; no explicit where('team_id', ...) needed here anymore.
        $query = Employee::with('position');

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $employees = $query->orderBy('last_name')->paginate(20)->withQueryString();

        return view('employees.index', compact('employees'));
    }

    public function create(Request $request)
    {
        $this->authorize('create', Employee::class);

        $positions = Position::orderBy('title')->get();

        return view('employees.create', compact('positions'));
    }

    public function store(Request $request)
    {
        $this->authorize('create', Employee::class);

        $team = $request->user()->currentTeam;

        $validated = $this->validateEmployee($request, $team->id);
        $validated['team_id'] = $team->id;
        $validated['created_by'] = $request->user()->id;

        Employee::create($validated);

        return redirect()->route('employees.index')->with('status', 'Employee added.');
    }

    public function show(Request $request, Employee $employee)
    {
        $this->authorize('view', $employee);

        $employee->load('position', 'leaveRequests');

        return view('employees.show', compact('employee'));
    }

    public function edit(Request $request, Employee $employee)
    {
        $this->authorize('update', $employee);

        $positions = Position::orderBy('title')->get();

        return view('employees.edit', compact('employee', 'positions'));
    }

    public function update(Request $request, Employee $employee)
    {
        $this->authorize('update', $employee);

        $validated = $this->validateEmployee($request, $employee->team_id, $employee->id);

        $employee->update($validated);

        return redirect()->route('employees.show', $employee)->with('status', 'Employee updated.');
    }

    public function destroy(Request $request, Employee $employee)
    {
        $this->authorize('delete', $employee);

        $employee->delete();

        return redirect()->route('employees.index')->with('status', 'Employee removed.');
    }

    protected function validateEmployee(Request $request, int $teamId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'position_id' => [
                'nullable',
                Rule::exists('positions', 'id')->where('team_id', $teamId),
            ],
            'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'contractor'])],
            'status' => ['required', Rule::in(['active', 'on_leave', 'terminated'])],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
    }
}
