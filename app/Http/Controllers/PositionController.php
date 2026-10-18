<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Positions: what roles the team has and who holds them. Describes work, not workers
 * (wiki.md section 2). Admins manage and assign; managers read the list with headcounts.
 */
class PositionController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Position::class);

        $positions = Position::withCount('employees')->orderBy('title')->get();

        return view('positions.index', compact('positions'));
    }

    public function create(Request $request)
    {
        $this->authorize('create', Position::class);

        return view('positions.create');
    }

    public function store(Request $request)
    {
        $this->authorize('create', Position::class);

        $team = $request->user()->currentTeam;

        $position = Position::create($this->validatePosition($request) + ['team_id' => $team->id]);

        return redirect()->route('positions.index')->with('status', 'Position "'.$position->title.'" added.');
    }

    public function edit(Request $request, Position $position)
    {
        $this->authorize('update', $position);

        $employees = Employee::with('position')->orderBy('last_name')->orderBy('first_name')->get();

        return view('positions.edit', compact('position', 'employees'));
    }

    public function update(Request $request, Position $position)
    {
        $this->authorize('update', $position);

        $validated = $this->validatePosition($request);
        $assign = $request->has('employee_ids') || $request->boolean('assignments');

        $ids = [];

        if ($assign) {
            $ids = $request->validate([
                'employee_ids' => ['nullable', 'array'],
                'employee_ids.*' => [
                    'integer',
                    Rule::exists('employees', 'id')->where('team_id', $position->team_id)->whereNull('deleted_at'),
                ],
            ])['employee_ids'] ?? [];
        }

        DB::transaction(function () use ($position, $validated, $assign, $ids): void {
            $position->update($validated);

            if ($assign) {
                $this->syncAssignments($position, array_map('intval', $ids));
            }
        });

        return redirect()->route('positions.index')->with('status', 'Position updated.');
    }

    public function destroy(Request $request, Position $position)
    {
        $this->authorize('delete', $position);

        DB::transaction(function () use ($position): void {
            // Unassign through the model so each change reaches the audit log.
            $this->syncAssignments($position, []);
            $position->delete();
        });

        return redirect()->route('positions.index')->with('status', 'Position removed.');
    }

    /** Employees ticked are assigned to this position; employees currently on it but not ticked are unassigned. */
    private function syncAssignments(Position $position, array $employeeIds): void
    {
        Employee::where('position_id', $position->id)->whereNotIn('id', $employeeIds)->get()
            ->each(fn (Employee $e) => $e->update(['position_id' => null]));

        Employee::whereIn('id', $employeeIds)->where(fn ($q) => $q->whereNull('position_id')->orWhere('position_id', '!=', $position->id))->get()
            ->each(fn (Employee $e) => $e->update(['position_id' => $position->id]));
    }

    private function validatePosition(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
