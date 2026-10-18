<?php

namespace App\Http\Controllers;

use App\Actions\Hr\CancelLeaveRequest;
use App\Actions\Hr\DecideLeaveRequest;
use App\Actions\Hr\LeaveStateException;
use App\Actions\Hr\SubmitLeaveRequest;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Hr\Audit;
use App\Services\Hr\LeaveBalance;
use App\Support\Hr\HrAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Leave: listing, requesting, the decision, cancelling. Scoped to the current team
 * (HasTeamScope) and gated by LeaveRequestPolicy; the rules for requesting live in
 * SubmitLeaveRequest, for deciding in DecideLeaveRequest.
 */
class LeaveRequestController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $access = HrAccess::current($request->user());

        // HasTeamScope already restricts this to the current team. Requests of a removed
        // employee are retained but not listed.
        $query = LeaveRequest::with('employee')
            ->whereHas('employee', fn ($q) => $q->withoutTrashed());

        // A linked member who is neither admin nor manager sees only their own leave.
        if (! $access->isAdmin() && ! $access->isManager()) {
            $query->where('employee_id', $access->employee()->id);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $leaveRequests = $query->with('leaveType')->latest('start_date')->paginate(20)->withQueryString();

        return view('leave-requests.index', [
            'leaveRequests' => $leaveRequests,
            'showType' => $access->isAdmin(),
            'heading' => $access->isAdmin() || $access->isManager() ? 'Leave' : 'My leave',
        ]);
    }

    public function create(Request $request, LeaveBalance $balances)
    {
        $this->authorize('create', LeaveRequest::class);

        $access = HrAccess::current($request->user());
        $mine = $access->employee();

        $employees = $access->isAdmin()
            ? Employee::where('status', '!=', 'terminated')->orderBy('last_name')->get()
            : collect([$mine]);

        $leaveTypes = LeaveType::orderBy('name')->get();

        // Balances are shown for the person the form defaults to: the signed-in employee.
        $snapshots = $mine
            ? $leaveTypes->filter->isTracked()->mapWithKeys(fn (LeaveType $type) => [$type->id => $balances->for($mine, $type, now())])
            : collect();

        return view('leave-requests.create', [
            'employees' => $employees,
            'leaveTypes' => $leaveTypes,
            'snapshots' => $snapshots,
            'mine' => $mine,
            'isAdmin' => $access->isAdmin(),
        ]);
    }

    public function store(Request $request, SubmitLeaveRequest $submit)
    {
        $this->authorize('create', LeaveRequest::class);

        $team = $request->user()->currentTeam;
        $access = HrAccess::current($request->user());

        $validated = $request->validate([
            'employee_id' => [
                'nullable',
                Rule::exists('employees', 'id')->where('team_id', $team->id)->whereNull('deleted_at'),
            ],
            'leave_type_id' => [
                'required',
                Rule::exists('leave_types', 'id')->where('team_id', $team->id),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'half_day' => ['nullable', Rule::in(['am', 'pm'])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $employee = filled($validated['employee_id'] ?? null)
            ? Employee::findOrFail($validated['employee_id'])
            : $access->employee();

        // An admin with no linked record must say who the leave is for.
        abort_if($employee === null, 422, 'Choose the employee this leave is for.');

        $this->authorize('createFor', [LeaveRequest::class, $employee]);

        $submit($request->user(), $employee, $validated);

        return redirect()->route('leave-requests.index')->with('status', 'Leave request submitted.');
    }

    public function show(Request $request, LeaveRequest $leaveRequest)
    {
        $this->authorize('view', $leaveRequest);

        $user = $request->user();
        $canSeeReason = $user->can('viewReason', $leaveRequest);

        $leaveRequest->load('employee', 'requestedBy', 'reviewedBy', 'leaveType');

        // Opening someone else's leave is an access event, with whether the reason was in view.
        // The employee opening their own is not.
        $isSubject = $leaveRequest->employee->user_id !== null && $leaveRequest->employee->user_id === $user->id;

        if (! $isSubject) {
            Audit::record('leave.viewed', $leaveRequest, [], ['reason_visible' => $canSeeReason]);
        }

        return view('leave-requests.show', [
            'leaveRequest' => $leaveRequest,
            'canSeeReason' => $canSeeReason,
            'decision' => Gate::forUser($user)->inspect('decide', $leaveRequest),
            'canCancel' => $user->can('cancel', $leaveRequest),
            'canDelete' => $user->can('delete', $leaveRequest),
        ]);
    }

    public function approve(Request $request, LeaveRequest $leaveRequest, DecideLeaveRequest $decide)
    {
        return $this->decide($request, $leaveRequest, $decide, LeaveRequest::STATUS_APPROVED, 'Leave request approved.');
    }

    public function deny(Request $request, LeaveRequest $leaveRequest, DecideLeaveRequest $decide)
    {
        return $this->decide($request, $leaveRequest, $decide, LeaveRequest::STATUS_DENIED, 'Leave request denied.');
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest, CancelLeaveRequest $cancel)
    {
        $this->authorize('cancel', $leaveRequest);

        try {
            $cancel($leaveRequest);
        } catch (LeaveStateException $e) {
            return redirect()->route('leave-requests.show', $leaveRequest)->with('error', $e->getMessage());
        }

        return redirect()->route('leave-requests.index')->with('status', 'Leave request cancelled.');
    }

    public function destroy(Request $request, LeaveRequest $leaveRequest)
    {
        $this->authorize('delete', $leaveRequest);

        // Soft delete: kept until hr:purge-expired removes it after the retention period.
        $leaveRequest->delete();

        return redirect()->route('leave-requests.index')->with('status', 'Leave request removed.');
    }

    private function decide(Request $request, LeaveRequest $leaveRequest, DecideLeaveRequest $decide, string $decision, string $message)
    {
        $this->authorize('decide', $leaveRequest);

        try {
            $decide($request->user(), $leaveRequest, $decision);
        } catch (LeaveStateException $e) {
            return redirect()->route('leave-requests.show', $leaveRequest)->with('error', $e->getMessage());
        }

        return redirect()->route('leave-requests.show', $leaveRequest)->with('status', $message);
    }
}
