<?php

namespace App\Http\Controllers;

use App\Actions\Hr\LinkEmployeesByEmail;
use App\Actions\Hr\LinkEmployeeToUser;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Services\Hr\Audit;
use App\Support\Hr\EmailIndex;
use App\Support\Hr\HrAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Employee records, scoped to the current team throughout (HasTeamScope) and gated by
 * EmployeePolicy, whose roles come from HrAccess. What each role sees on a record:
 * admin and the person themselves get everything; a manager (editor) gets the basics.
 * Deleting is soft; removed records can be restored by an admin until the retention
 * purge deletes them for good.
 */
class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Employee::class);

        $access = HrAccess::current($request->user());
        $showRemoved = $access->isAdmin() && $request->boolean('removed');

        // HasTeamScope already restricts this to the current team.
        $query = Employee::with('position')->withOnLeaveFlag();

        if ($showRemoved) {
            $query->onlyTrashed();
        }

        // A linked member who is neither admin nor manager sees only their own row.
        if (! $access->isAdmin() && ! $access->isManager()) {
            $query->whereKey($access->employee()->id);
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search, $access) {
                $q->where('first_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%');

                // Email is encrypted: admins can look up an exact address through the blind index.
                // Managers cannot, or the search box would confirm anyone's address.
                if ($access->isAdmin() && ($hash = EmailIndex::hash($search))) {
                    $q->orWhere('email_hash', $hash);
                }
            });
        }

        match ($request->query('status')) {
            'active' => $query->atWork(),
            'on_leave' => $query->onLeaveOn(),
            'terminated' => $query->where('status', 'terminated'),
            default => null,
        };

        $employees = $query->orderBy('last_name')->orderBy('first_name')->paginate(20)->withQueryString();

        return view('employees.index', compact('employees', 'showRemoved'));
    }

    public function create(Request $request)
    {
        $this->authorize('create', Employee::class);

        $positions = Position::orderBy('title')->get();

        return view('employees.create', compact('positions'));
    }

    public function store(Request $request, LinkEmployeesByEmail $linker)
    {
        $this->authorize('create', Employee::class);

        $team = $request->user()->currentTeam;

        $validated = $this->validateEmployee($request, $team->id);
        $validated['team_id'] = $team->id;
        $validated['created_by'] = $request->user()->id;

        $employee = Employee::create($validated);

        // If a team member already signs in with this verified email, they are this person.
        $linker->forEmployee($employee);

        return redirect()->route('employees.index')->with('status', 'Employee added.');
    }

    public function show(Request $request, Employee $employee)
    {
        $this->authorize('view', $employee);

        $user = $request->user();
        $canSeeContact = $user->can('viewContact', $employee);

        // Looking at someone else's record is an access event. The subject looking at their own is not.
        if (! HrAccess::forTeamId($user, $employee->team_id)->isSubject($employee)) {
            Audit::record('employee.viewed', $employee, [], ['contact_visible' => $canSeeContact]);
        }

        $employee->load('position');

        $leaveRequests = $canSeeContact
            ? $employee->leaveRequests()->with('leaveType')->latest('start_date')->get()
            : collect();

        $linkableUsers = collect();

        if ($user->can('link', $employee)) {
            $linkedIds = Employee::where('team_id', $employee->team_id)->whereNotNull('user_id')->pluck('user_id');
            $linkableUsers = $employee->team->allUsers()->reject(fn (User $u) => $linkedIds->contains($u->id))->sortBy('name')->values();
            $employee->loadMissing('user');
        }

        return view('employees.show', [
            'employee' => $employee,
            'canSeeContact' => $canSeeContact,
            'leaveRequests' => $leaveRequests,
            'linkableUsers' => $linkableUsers,
            // Documents are HR-administrator business only (R-32 §10).
            'documents' => HrAccess::forTeamId($user, $employee->team_id)?->isAdmin()
                ? $employee->documents()->with(['acknowledgements' => fn ($q) => $q->where('employee_id', $employee->id)])->get()
                : null,
            // Employee relations cases (brief §15): only for those who handle them, and only
            // the ones they may open. Never managers.
            'cases' => ($access = HrAccess::forTeamId($user, $employee->team_id))?->canHandleCases()
                ? $access->visibleCases()->where('employee_id', $employee->id)->latest()->get()
                : null,
        ]);
    }

    public function edit(Request $request, Employee $employee)
    {
        $this->authorize('update', $employee);

        $positions = Position::orderBy('title')->get();

        return view('employees.edit', compact('employee', 'positions'));
    }

    public function update(Request $request, Employee $employee, LinkEmployeesByEmail $linker)
    {
        $this->authorize('update', $employee);

        $validated = $this->validateEmployee($request, $employee->team_id, $employee->id);

        $employee->update($validated);

        if ($employee->wasChanged('email')) {
            $linker->forEmployee($employee->fresh());
        }

        return redirect()->route('employees.show', $employee)->with('status', 'Employee updated.');
    }

    public function destroy(Request $request, Employee $employee)
    {
        $this->authorize('delete', $employee);

        // Soft delete: the record and its leave history are kept until hr:purge-expired removes them.
        $employee->delete();

        return redirect()->route('employees.index')->with('status', 'Employee removed. Their record is kept for the retention period and an admin can restore it until then.');
    }

    public function restore(Request $request, Employee $employee)
    {
        $this->authorize('restore', $employee);

        if (! $employee->trashed()) {
            return redirect()->route('employees.show', $employee);
        }

        if ($employee->email_hash !== null && $this->emailTaken($employee->team_id, $employee->email_hash, $employee->id)) {
            throw ValidationException::withMessages(['email' => 'Another employee now uses this email address, so this record cannot be restored as it is. Change that record first.']);
        }

        // The account may have been linked to someone else while this record was away.
        $taken = $employee->user_id !== null && Employee::where('team_id', $employee->team_id)->where('user_id', $employee->user_id)->exists();

        if ($taken) {
            $employee->forceFill(['user_id' => null])->save();
        }

        $employee->restore();

        return redirect()->route('employees.show', $employee)->with('status', 'Employee restored.');
    }

    public function link(Request $request, Employee $employee, LinkEmployeeToUser $linker)
    {
        $this->authorize('link', $employee);

        $data = $request->validate(['user_id' => ['required', 'integer']]);

        $user = User::find($data['user_id']);

        if (! $user) {
            throw ValidationException::withMessages(['user_id' => 'That person is not a member of this team.']);
        }

        $linker($employee, $user);

        return redirect()->route('employees.show', $employee)->with('status', 'Linked to '.$user->name.'. They now see only their own record and leave.');
    }

    public function unlink(Request $request, Employee $employee, LinkEmployeeToUser $linker)
    {
        $this->authorize('link', $employee);

        $linker->unlink($employee);

        return redirect()->route('employees.show', $employee)->with('status', 'Account unlinked.');
    }

    protected function validateEmployee(Request $request, int $teamId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => [
                'nullable', 'email', 'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($teamId, $ignoreId) {
                    $hash = EmailIndex::hash($value);

                    if ($hash !== null && $this->emailTaken($teamId, $hash, $ignoreId)) {
                        $fail('Another employee in this team already has this email address.');
                    }
                },
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'position_id' => [
                'nullable',
                Rule::exists('positions', 'id')->where('team_id', $teamId),
            ],
            'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'contractor'])],
            // "On leave" is not a status you set: it follows approved leave by itself.
            'status' => ['required', Rule::in(['active', 'terminated'])],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
    }

    private function emailTaken(int $teamId, string $hash, ?int $ignoreId): bool
    {
        return Employee::withoutTeamScope(fn () => Employee::where('team_id', $teamId)
            ->where('email_hash', $hash)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists());
    }
}
