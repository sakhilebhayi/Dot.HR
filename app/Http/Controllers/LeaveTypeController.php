<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Leave types: what the team offers, how many days a year, how much carries over, and
 * whether a request beyond the balance is refused. Admin-only configuration. The slug is
 * fixed at creation: requests keep a snapshot of it, so renaming never rewrites history.
 */
class LeaveTypeController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', LeaveType::class);

        $types = LeaveType::withCount(['leaveRequests as requests_count' => fn ($q) => $q->withTrashed()])->orderBy('name')->get();

        return view('leave-types.index', compact('types'));
    }

    public function create(Request $request)
    {
        $this->authorize('create', LeaveType::class);

        return view('leave-types.create');
    }

    public function store(Request $request)
    {
        $this->authorize('create', LeaveType::class);

        $team = $request->user()->currentTeam;
        $data = $this->validated($request);

        LeaveType::create($data + [
            'team_id' => $team->id,
            'slug' => LeaveType::uniqueSlug($team->id, $data['name']),
        ]);

        return redirect()->route('leave-types.index')->with('status', 'Leave type added.');
    }

    public function edit(Request $request, LeaveType $leaveType)
    {
        $this->authorize('update', $leaveType);

        return view('leave-types.edit', ['type' => $leaveType]);
    }

    public function update(Request $request, LeaveType $leaveType)
    {
        $this->authorize('update', $leaveType);

        $leaveType->update($this->validated($request));

        return redirect()->route('leave-types.index')->with('status', 'Leave type updated.');
    }

    public function destroy(Request $request, LeaveType $leaveType)
    {
        $this->authorize('delete', $leaveType);

        if (LeaveRequest::withTrashed()->where('leave_type_id', $leaveType->id)->exists()) {
            throw ValidationException::withMessages(['type' => 'This type has leave requests on file, so it cannot be deleted. Set its entitlement as needed instead.']);
        }

        $leaveType->delete();

        return redirect()->route('leave-types.index')->with('status', 'Leave type removed.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'annual_days' => ['nullable', 'numeric', 'min:0', 'max:366'],
            'carry_over_days' => ['nullable', 'numeric', 'min:0', 'max:366'],
        ]);

        return [
            'name' => $data['name'],
            'annual_days' => filled($data['annual_days'] ?? null) ? $data['annual_days'] : null,
            'carry_over_days' => filled($data['carry_over_days'] ?? null) ? $data['carry_over_days'] : 0,
            'is_paid' => $request->boolean('is_paid'),
            'enforce_balance' => $request->boolean('enforce_balance'),
        ];
    }
}
