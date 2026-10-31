<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\HrRequest;
use App\Models\LifecycleChecklist;
use App\Models\LifecycleTask;
use App\Models\User;
use App\Notifications\LifecycleNotification;
use App\Services\Hr\Lifecycle;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Employee self-service in the current business (R-32 Phase 5, brief §7): your own record,
 * the steps a checklist asks of you, your emergency contact and phone, and your HR requests.
 * Everything acts on the employee record linked to the signed-in account — there is no
 * employee id anywhere in these routes to tamper with.
 */
class MyWorkController extends Controller
{
    public function __construct(private Lifecycle $lifecycle) {}

    public function index(Request $request): View
    {
        $employee = $this->me($request);

        $checklists = LifecycleChecklist::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'open')
            ->with(['tasks' => fn ($q) => $q->where('owner', 'employee')])
            ->get();

        return view('my-work.index', [
            'employee' => $employee->load('position'),
            'checklists' => $checklists,
            'requests' => HrRequest::query()->where('employee_id', $employee->id)->latest()->get(),
        ]);
    }

    public function updateDetails(Request $request): RedirectResponse
    {
        $employee = $this->me($request);

        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\s-]*$/'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\s-]*$/'],
        ]);

        // Only these three fields: a person never edits their own title, pay, type or dates.
        // The observer audits which fields changed — never their values.
        $employee->forceFill([
            'phone' => $data['phone'] ?? null,
            'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
            'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
        ])->save();

        return back()->with('status', 'Your details are saved.');
    }

    public function completeTask(Request $request, LifecycleTask $task): RedirectResponse
    {
        $employee = $this->me($request);

        abort_unless($task->owner === 'employee' && $task->checklist->employee_id === $employee->id, 404);

        if ($task->kind === 'acknowledge') {
            $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Tick the box to confirm you have read it.']);
        }

        $this->lifecycle->setDone($task, true, $request->user()->id);

        return back()->with('status', $task->kind === 'acknowledge' ? 'Recorded that you read "'.$task->title.'".' : 'Done: '.$task->title.'.');
    }

    public function storeRequest(Request $request): RedirectResponse
    {
        $employee = $this->me($request);

        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(HrRequest::CATEGORIES))],
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $hrRequest = new HrRequest($data);
        $hrRequest->forceFill([
            'team_id' => $employee->team_id,
            'employee_id' => $employee->id,
            'requested_by' => $request->user()->id,
            'status' => 'open',
        ])->save();

        // HR administrators hear that something arrived — not what it says.
        $team = $employee->team;
        $admins = $team->allUsers()->filter(fn (User $u) => $team->user_id === $u->id || $u->hasTeamRole($team, 'admin'));
        Notification::send($admins, new LifecycleNotification(
            'New HR request',
            $employee->fullName().' raised '.$hrRequest->reference().' ('.strtolower(explode(' (', HrRequest::CATEGORIES[$data['category']])[0]).').',
            route('requests.show', $hrRequest),
        ));

        return redirect()->route('my-work.requests.show', $hrRequest)->with('status', 'Sent to HR. You will see the answer here.');
    }

    public function showRequest(Request $request, HrRequest $hrRequest): View
    {
        $employee = $this->me($request);
        abort_unless($hrRequest->employee_id === $employee->id, 404);

        return view('my-work.request', ['hrRequest' => $hrRequest]);
    }

    private function me(Request $request): Employee
    {
        $employee = HrAccess::current($request->user())?->employee();
        abort_if($employee === null, 404);

        return $employee;
    }
}
