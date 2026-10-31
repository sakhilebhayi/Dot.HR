<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Goal;
use App\Models\Review;
use App\Models\User;
use App\Services\Hr\Audit;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Goals (R-32 Phase 6b): set and kept current by the person, by whoever reviews them, or by
 * an HR administrator — the same three who can read the person's reviews.
 */
class GoalController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorise($request->user(), $employee);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'detail' => ['nullable', 'string', 'max:2000'],
            'kind' => ['required', Rule::in(array_keys(Goal::KINDS))],
            'due_on' => ['nullable', 'date'],
        ]);

        $goal = new Goal;
        $goal->forceFill([...$data, 'team_id' => $employee->team_id, 'employee_id' => $employee->id, 'status' => 'open', 'created_by' => $request->user()->id])->save();
        Audit::record('goal.created', $goal, ['title', 'kind', 'due_on']);

        return back()->with('status', 'Goal added.');
    }

    public function update(Request $request, Goal $goal): RedirectResponse
    {
        $this->authorise($request->user(), $goal->employee);
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Goal::STATUSES))]]);

        $from = $goal->status;
        $goal->forceFill(['status' => $data['status']])->save();
        Audit::record('goal.updated', $goal, ['status'], ['status' => ['from' => $from, 'to' => $data['status']]]);

        return back()->with('status', '"'.$goal->title.'": '.strtolower(Goal::STATUSES[$data['status']]).'.');
    }

    private function authorise(User $user, Employee $employee): void
    {
        $access = HrAccess::current($user);
        abort_unless($access !== null, 403);

        $allowed = $access->isAdmin()
            || ($employee->user_id !== null && $employee->user_id === $user->id)
            || Review::query()->where('employee_id', $employee->id)->where('reviewer_id', $user->id)->exists();

        abort_unless($allowed, 403);
    }
}
