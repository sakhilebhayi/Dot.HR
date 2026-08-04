<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD + approve/deny workflow for LeaveRequest, scoped to the current team.
 * Every by-ID action authorizes via LeaveRequestPolicy — see its docblock.
 */
class LeaveRequestController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', LeaveRequest::class);

        // HasTeamScope's global scope already restricts this to the current
        // team; no explicit where('team_id', ...) needed here anymore.
        $query = LeaveRequest::with('employee');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $leaveRequests = $query->latest('start_date')->paginate(20)->withQueryString();

        return view('leave-requests.index', compact('leaveRequests'));
    }

    public function create(Request $request)
    {
        $this->authorize('create', LeaveRequest::class);

        $employees = Employee::where('status', '!=', 'terminated')
            ->orderBy('last_name')
            ->get();

        return view('leave-requests.create', compact('employees'));
    }

    public function store(Request $request)
    {
        $this->authorize('create', LeaveRequest::class);

        $team = $request->user()->currentTeam;

        $validated = $request->validate([
            'employee_id' => [
                'required',
                Rule::exists('employees', 'id')->where('team_id', $team->id),
            ],
            'type' => ['required', Rule::in(['annual', 'sick', 'unpaid', 'other'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['team_id'] = $team->id;
        $validated['requested_by'] = $request->user()->id;
        $validated['status'] = 'pending';

        LeaveRequest::create($validated);

        return redirect()->route('leave-requests.index')->with('status', 'Leave request submitted.');
    }

    public function show(Request $request, LeaveRequest $leaveRequest)
    {
        $this->authorize('view', $leaveRequest);

        $leaveRequest->load('employee', 'requestedBy', 'reviewedBy');

        return view('leave-requests.show', compact('leaveRequest'));
    }

    /**
     * Approve a pending leave request. Uses the update ability — approving
     * is a mutation of the leave request's own record, gated the same as
     * any other update.
     */
    public function approve(Request $request, LeaveRequest $leaveRequest)
    {
        $this->authorize('update', $leaveRequest);

        $leaveRequest->update([
            'status' => 'approved',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return redirect()->route('leave-requests.show', $leaveRequest)->with('status', 'Leave request approved.');
    }

    public function deny(Request $request, LeaveRequest $leaveRequest)
    {
        $this->authorize('update', $leaveRequest);

        $leaveRequest->update([
            'status' => 'denied',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return redirect()->route('leave-requests.show', $leaveRequest)->with('status', 'Leave request denied.');
    }

    public function destroy(Request $request, LeaveRequest $leaveRequest)
    {
        $this->authorize('delete', $leaveRequest);

        $leaveRequest->delete();

        return redirect()->route('leave-requests.index')->with('status', 'Leave request removed.');
    }
}
