<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\HrCase;
use App\Models\ImprovementPlan;
use App\Models\User;
use App\Services\Hr\Audit;
use App\Services\Hr\ImprovementPlans;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Performance improvement plans, HR's side (brief §10 and §15). HR administrators draft them;
 * the member named as the supporting manager runs check-ins with HR. Nobody else sees a plan;
 * one they may not open answers 404. Every view is audited.
 */
class PlanController extends Controller
{
    public function __construct(private ImprovementPlans $plans) {}

    public function index(Request $request): View
    {
        $access = HrAccess::current($request->user());
        abort_if($access === null, 403);
        $query = ImprovementPlan::query()->when(! $access->isAdmin(), fn ($q) => $q->where('manager_user_id', $request->user()->id));
        abort_unless($access->isAdmin() || (clone $query)->exists(), 403);

        return view('plans.index', [
            'plans' => $query->with(['employee', 'manager'])
                ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END")->orderBy('ends_on')->get(),
            'admin' => $access->isAdmin(),
        ]);
    }

    public function create(Request $request): View
    {
        $access = $this->admin($request);
        $employee = $request->filled('employee') ? Employee::query()->find($request->integer('employee')) : null;

        return view('plans.form', $this->formData($access, $employee, null));
    }

    public function store(Request $request): RedirectResponse
    {
        $access = $this->admin($request);
        $data = $this->validated($request, $access, true);
        $employee = Employee::query()->findOrFail($data['employee_id']);

        $plan = $this->plans->draft($access->team(), $employee, $data['plan'], $data['objectives'], $data['manager'], $request->user(),
            case: $data['case'], training: $data['training']);

        return redirect()->route('plans.show', $plan)->with('status', $plan->reference().' drafted. Nothing is shared until you share it.');
    }

    public function show(Request $request, ImprovementPlan $plan): View
    {
        $access = $this->manage($request, $plan);
        Audit::record('plan.viewed', $plan, [], [], $plan->team_id);

        return view('plans.show', [
            'plan' => $plan->load(['employee', 'manager', 'case', 'objectives', 'checkIns.author']),
            'admin' => $access->isAdmin(),
        ]);
    }

    public function edit(Request $request, ImprovementPlan $plan): View
    {
        $access = $this->manage($request, $plan);
        abort_unless($plan->status === 'draft', 404);

        return view('plans.form', $this->formData($access, $plan->employee, $plan->load('objectives')));
    }

    public function update(Request $request, ImprovementPlan $plan): RedirectResponse
    {
        $access = $this->manage($request, $plan);
        $data = $this->validated($request, $access, false);
        $this->plans->revise($plan, $data['plan'], $data['objectives'], $access->isAdmin() ? $data['manager'] : $plan->manager, $data['case']);

        return redirect()->route('plans.show', $plan)->with('status', 'Draft saved.');
    }

    public function share(Request $request, ImprovementPlan $plan): RedirectResponse
    {
        $this->manage($request, $plan);
        $caution = $this->plans->share($plan, $request->user());

        return back()->with('status', 'Shared. '.$plan->employee->first_name.' has been told.'.($caution ? ' '.$caution : ''));
    }

    public function checkIn(Request $request, ImprovementPlan $plan): RedirectResponse
    {
        $this->manage($request, $plan);
        $data = $request->validate([
            'held_on' => ['required', 'date'],
            'notes' => ['required', 'string', 'min:10', 'max:10000'],
            'objective' => ['nullable', 'array'],
            'objective.*' => ['nullable', 'in:open,met,not_met'],
        ]);

        $this->plans->checkIn($plan, Carbon::parse($data['held_on']), $data['notes'], array_filter($data['objective'] ?? []), $request->user());

        return back()->with('status', 'Check-in recorded and shared.');
    }

    public function extend(Request $request, ImprovementPlan $plan): RedirectResponse
    {
        $this->manage($request, $plan);
        $data = $request->validate(['ends_on' => ['required', 'date'], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $this->plans->extend($plan, Carbon::parse($data['ends_on']), $data['reason'], $request->user());

        return back()->with('status', 'Extended. They have been told.');
    }

    public function complete(Request $request, ImprovementPlan $plan): RedirectResponse
    {
        $this->manage($request, $plan);
        $data = $request->validate(['outcome' => ['required', Rule::in(array_keys(ImprovementPlan::OUTCOMES))], 'notes' => ['required', 'string', 'min:10', 'max:10000']]);
        $this->plans->complete($plan, $data['outcome'], $data['notes'], $request->user());

        return back()->with('status', $data['outcome'] === 'met'
            ? 'Completed: the standard is met. They have been told.'
            : 'Completed: the standard is not met. Any further step is an incapacity enquiry, opened as an HR case.');
    }

    public function cancel(Request $request, ImprovementPlan $plan): RedirectResponse
    {
        $this->manage($request, $plan);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $this->plans->cancel($plan, $data['reason'], $request->user());

        return back()->with('status', 'Plan cancelled.');
    }

    /** @return array<string, mixed> */
    private function formData(HrAccess $access, ?Employee $employee, ?ImprovementPlan $plan): array
    {
        return [
            'plan' => $plan,
            'employee' => $employee,
            'admin' => $access->isAdmin(),
            'employees' => Employee::query()->where('status', '!=', 'terminated')->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'user_id']),
            'managers' => $employee ? $this->plans->managersFor($access->team(), $employee) : $this->plans->managersFor($access->team(), new Employee),
            // Open performance (incapacity) cases this person may link to, so the plan sits inside the case.
            'cases' => $access->visibleCases()->where('type', 'disciplinary')->where('category', 'performance')
                ->whereNotIn('status', ['closed', 'withdrawn'])->with('employee')->get(),
        ];
    }

    /** @return array{employee_id: int|null, plan: array<string, mixed>, objectives: list<array{title: string, measure: string, due_on: string|null}>, manager: User|null, case: HrCase|null, training: string|null} */
    private function validated(Request $request, HrAccess $access, bool $creating): array
    {
        $teamId = $access->team()->id;
        $data = $request->validate([
            'employee_id' => [$creating ? 'required' : 'prohibited', Rule::exists('employees', 'id')->where('team_id', $teamId)],
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'concern' => ['required', 'string', 'min:10', 'max:10000'],
            'standard' => ['required', 'string', 'min:10', 'max:10000'],
            'support' => ['required', 'string', 'min:10', 'max:10000'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'check_in_days' => ['required', 'integer', 'min:7', 'max:60'],
            'manager_user_id' => ['nullable', 'integer'],
            'hr_case_id' => ['nullable', 'integer'],
            'training' => ['nullable', 'string', 'max:160'],
            'objectives' => ['required', 'array', 'max:8'],
            'objectives.*.title' => ['nullable', 'string', 'max:200'],
            'objectives.*.measure' => ['nullable', 'required_with:objectives.*.title', 'string', 'max:255'],
            'objectives.*.due_on' => ['nullable', 'date'],
        ], ['objectives.*.measure.required_with' => 'Say how each objective will be measured.']);

        $objectives = collect($data['objectives'])->filter(fn ($o) => filled($o['title'] ?? null))
            ->map(fn ($o) => ['title' => (string) $o['title'], 'measure' => (string) $o['measure'], 'due_on' => $o['due_on'] ?? null])->values()->all();
        if ($objectives === []) {
            throw ValidationException::withMessages(['objectives' => 'Add at least one objective.']);
        }

        $case = null;
        if (! empty($data['hr_case_id'])) {
            $case = HrCase::query()->find($data['hr_case_id']);
            abort_if($case === null || ! $access->canSeeCase($case), 422, 'Choose a case you can open.');
        }

        return [
            'employee_id' => $data['employee_id'] ?? null,
            'plan' => collect($data)->only(['title', 'concern', 'standard', 'support', 'starts_on', 'ends_on', 'check_in_days'])->all(),
            'objectives' => $objectives,
            'manager' => ! empty($data['manager_user_id']) ? User::query()->find($data['manager_user_id']) : null,
            'case' => $case,
            'training' => $data['training'] ?? null,
        ];
    }

    private function admin(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->isAdmin(), 403);

        return $access;
    }

    private function manage(Request $request, ImprovementPlan $plan): HrAccess
    {
        $access = HrAccess::forTeamId($request->user(), $plan->team_id);
        abort_unless($access?->canManagePlan($plan), 404);

        return $access;
    }
}
