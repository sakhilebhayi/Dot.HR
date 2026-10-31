<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Models\Engagement;
use App\Models\EngagementMilestone;
use App\Models\Goal;
use App\Models\HrRequest;
use App\Models\LifecycleChecklist;
use App\Models\LifecycleTask;
use App\Models\Review;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\TrainingRecord;
use App\Models\User;
use App\Notifications\LifecycleNotification;
use App\Services\Hr\Audit;
use App\Services\Hr\Engagements;
use App\Services\Hr\Lifecycle;
use App\Services\Hr\Workforce;
use App\Support\Hr\HrAccess;
use App\Support\Hr\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Employee self-service in the current business (R-32 Phase 5, brief §7): your own record,
 * the steps a checklist asks of you, your emergency contact and phone, and your HR requests.
 * Everything acts on the employee record linked to the signed-in account — there is no
 * employee id anywhere in these routes to tamper with.
 */
class MyWorkController extends Controller
{
    public function __construct(private Lifecycle $lifecycle, private Workforce $workforce, private Engagements $engagements) {}

    public function index(Request $request): View
    {
        $employee = $this->me($request);

        $checklists = LifecycleChecklist::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'open')
            ->with(['tasks' => fn ($q) => $q->where('owner', 'employee')])
            ->get();

        $thisWeek = LocalTime::today()->startOfWeek();
        $lastWeek = $thisWeek->copy()->subWeek();

        return view('my-work.index', [
            'employee' => $employee->load('position'),
            'checklists' => $checklists,
            'requests' => HrRequest::query()->where('employee_id', $employee->id)->latest()->get(),
            // Time (R-32 Phase 6a): the next fortnight's shifts, the clock, two weeks of hours.
            'shifts' => Shift::query()
                ->whereHas('employees', fn ($q) => $q->whereKey($employee->id))
                ->where('ends_at', '>=', LocalTime::now())
                ->where('starts_at', '<', LocalTime::now()->addDays(14))
                ->orderBy('starts_at')->get(),
            'openClock' => AttendanceRecord::query()->where('employee_id', $employee->id)->whereNull('clock_out_at')->first(),
            'weeks' => collect([$thisWeek, $lastWeek])->map(fn (Carbon $start) => [
                'start' => $start,
                'totals' => $this->workforce->week($employee, $start),
                'sheet' => Timesheet::query()->where('employee_id', $employee->id)->whereDate('week_start', $start->toDateString())->first(),
            ]),
            'certifications' => EmployeeCertification::query()->where('employee_id', $employee->id)->orderBy('expires_on')->get(),
            // Development and external work (R-32 Phase 6b).
            'reviews' => Review::query()->where('employee_id', $employee->id)->with(['cycle', 'reviewer'])->latest()->limit(4)->get(),
            'reviewing' => Review::query()->where('reviewer_id', $request->user()->id)->where('status', 'reviewer')->count(),
            'goals' => Goal::query()->where('employee_id', $employee->id)->where('status', '!=', 'dropped')
                ->orderByRaw("case status when 'done' then 1 else 0 end")->orderByRaw('due_on is null')->orderBy('due_on')->get(),
            'training' => TrainingRecord::query()->where('employee_id', $employee->id)->latest()->limit(8)->get(),
            'engagements' => Engagement::query()->where('employee_id', $employee->id)->where('status', 'active')->with(['milestones', 'invoices'])->get(),
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

    public function clock(Request $request, string $direction): RedirectResponse
    {
        $employee = $this->me($request);
        abort_unless(in_array($direction, ['in', 'out'], true), 404);

        try {
            $record = $direction === 'in'
                ? $this->workforce->clockIn($employee, $request->user()->id)
                : $this->workforce->clockOut($employee);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $direction === 'in'
            ? 'Clocked in at '.$record->clock_in_at->format('H:i').'.'
            : 'Clocked out at '.$record->clock_out_at?->format('H:i').' — '.Timesheet::hours($record->workedMinutes()).' worked.');
    }

    public function submitWeek(Request $request): RedirectResponse
    {
        $employee = $this->me($request);
        $data = $request->validate(['week_start' => ['required', 'date']]);

        try {
            $this->workforce->submit($employee, Carbon::parse($data['week_start']));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Timesheet sent for approval.');
    }

    public function requestTraining(Request $request): RedirectResponse
    {
        $employee = $this->me($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'provider' => ['nullable', 'string', 'max:140'],
            'kind' => ['required', Rule::in(array_keys(TrainingRecord::KINDS))],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $record = new TrainingRecord;
        $record->forceFill([...$data, 'team_id' => $employee->team_id, 'employee_id' => $employee->id, 'status' => 'requested', 'requested_by' => $request->user()->id])->save();
        Audit::record('training.requested', $record, ['title', 'kind', 'reason']);

        return back()->with('status', 'Asked for '.$record->title.'. You will hear when it is decided.');
    }

    public function storeGoal(Request $request): RedirectResponse
    {
        $employee = $this->me($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'kind' => ['required', Rule::in(array_keys(Goal::KINDS))],
            'due_on' => ['nullable', 'date'],
        ]);

        $goal = new Goal;
        $goal->forceFill([...$data, 'team_id' => $employee->team_id, 'employee_id' => $employee->id, 'status' => 'open', 'created_by' => $request->user()->id])->save();
        Audit::record('goal.created', $goal, ['title', 'kind', 'due_on']);

        return back()->with('status', 'Goal added.');
    }

    public function deliverMilestone(Request $request, EngagementMilestone $milestone): RedirectResponse
    {
        $employee = $this->me($request);
        abort_unless($milestone->engagement->employee_id === $employee->id, 404);

        try {
            $this->engagements->deliver($milestone);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $team = $employee->team;
        $admins = $team->allUsers()->filter(fn (User $u) => $team->user_id === $u->id || $u->hasTeamRole($team, 'admin'));
        Notification::send($admins, new LifecycleNotification(
            'A milestone was delivered',
            $employee->fullName().' marked "'.$milestone->title.'" as delivered.',
            route('contractors.show', $milestone->engagement),
        ));

        return back()->with('status', '"'.$milestone->title.'" marked as delivered. HR will approve it or say what is missing.');
    }

    private function me(Request $request): Employee
    {
        $employee = HrAccess::current($request->user())?->employee();
        abort_if($employee === null, 404);

        return $employee;
    }
}
