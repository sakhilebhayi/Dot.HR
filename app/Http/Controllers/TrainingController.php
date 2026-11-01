<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Models\Position;
use App\Models\TrainingBudget;
use App\Models\TrainingRecord;
use App\Models\User;
use App\Notifications\LifecycleNotification;
use App\Services\Hr\Audit;
use App\Services\Hr\LearningPaths;
use App\Services\Hr\Readiness;
use App\Services\Hr\TrainingBudgets;
use App\Services\Intelligence\Insights;
use App\Services\Intelligence\InsightsExport;
use App\Support\Hr\HrAccess;
use App\Support\Hr\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Training (R-32 Phase 6b, brief §10): requests from people, bookings, completions. Completed
 * training that carries a certification becomes a certification record, which closes the gap
 * in the readiness view. HR administrators and supervisors manage it; nobody decides their
 * own request.
 */
class TrainingController extends Controller
{
    public function index(Request $request, Readiness $readiness, Insights $insights): View
    {
        $access = $this->manager($request);

        return view('training.index', [
            'requested' => TrainingRecord::query()->where('status', 'requested')->with('employee')->oldest()->get(),
            'planned' => TrainingRecord::query()->where('status', 'planned')->with('employee')->orderByRaw('starts_on is null')->orderBy('starts_on')->get(),
            'completed' => TrainingRecord::query()->where('status', 'completed')->with('employee')->latest('completed_on')->limit(20)->get(),
            'gaps' => $readiness->gaps($access->team()->id),
            'suggestions' => $insights->trainingSuggestions($request->user()),
            'employees' => Employee::query()->where('status', '!=', 'terminated')->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'prefill' => ['employee_id' => $request->integer('employee_id') ?: null, 'certification_name' => (string) $request->query('for', '')],
            // Budgets are the business's money: HR administrators only.
            'isAdmin' => $access->isAdmin(),
            'budgets' => $access->isAdmin() ? app(TrainingBudgets::class)->overview() : collect(),
            'departments' => Position::query()->whereNotNull('department')->distinct()->orderBy('department')->pluck('department'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->manager($request);
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:160'],
            'provider' => ['nullable', 'string', 'max:140'],
            'kind' => ['required', Rule::in(array_keys(TrainingRecord::KINDS))],
            'starts_on' => ['nullable', 'date'],
            'certification_name' => ['nullable', 'string', 'max:140'],
            ...self::COST_RULES,
        ]);

        $employee = Employee::query()->findOrFail($data['employee_id']);
        $data = [...$data, ...$this->withCost($data)];
        $record = new TrainingRecord;
        $record->forceFill([...$data, 'team_id' => $employee->team_id, 'status' => 'planned', 'decided_by' => $request->user()->id, 'decided_at' => now()])->save();
        Audit::record('training.planned', $record, ['title', 'kind', 'starts_on', 'cost', 'paid_by']);
        $this->tell($employee, 'Training booked for you', $record->title.($record->starts_on ? ' on '.$record->starts_on->format('j M Y') : '').'.');

        return back()->with('status', $record->title.' booked for '.$employee->fullName().'.');
    }

    public function decide(Request $request, TrainingRecord $training, string $decision): RedirectResponse
    {
        $this->manager($request);
        abort_unless($training->status === 'requested', 422, 'Only a request can be approved or declined.');
        $data = $request->validate([
            'note' => [$decision === 'decline' ? 'required' : 'nullable', 'string', 'max:255'],
            'starts_on' => ['nullable', 'date'],
            ...self::COST_RULES,
        ], ['note.required' => 'Say why it is declined.']);

        if ($training->employee->user_id === $request->user()->id) {
            return back()->with('error', 'Someone else has to decide your own training request.');
        }

        $to = $decision === 'approve' ? 'planned' : 'declined';
        $training->forceFill([
            'status' => $to,
            'starts_on' => $data['starts_on'] ?? $training->starts_on,
            'decision_note' => $data['note'] ?? null,
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
            ...($to === 'planned' ? $this->withCost($data, keep: $training) : []),
        ])->save();
        Audit::record('training.'.$to, $training, ['status', 'decision_note'], ['status' => ['from' => 'requested', 'to' => $to]]);
        if ($to === 'declined') {
            app(LearningPaths::class)->trainingDeclined($training); // the path step can be asked for again
        }
        $this->tell($training->employee, $to === 'planned' ? 'Training approved' : 'Training request declined',
            $training->title.($to === 'planned' ? ' is approved.' : ': '.$data['note']));

        return back()->with('status', $training->title.' '.($to === 'planned' ? 'approved.' : 'declined.'));
    }

    public function complete(Request $request, TrainingRecord $training): RedirectResponse
    {
        $access = $this->manager($request);
        abort_unless($training->status === 'planned', 422, 'Only booked training can be completed.');
        $data = $request->validate([
            'completed_on' => ['required', 'date', 'before_or_equal:'.LocalTime::today()->toDateString()],
            'hours' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'certification_name' => ['nullable', 'string', 'max:140'],
            'certification_expires_on' => ['nullable', 'date', 'after:completed_on'],
            ...self::COST_RULES,
        ]);

        DB::transaction(function () use ($training, $data, $access) {
            $training->forceFill([
                'status' => 'completed',
                'completed_on' => $data['completed_on'],
                'hours' => $data['hours'] ?? null,
                'certification_name' => $data['certification_name'] ?? $training->certification_name,
                'certification_expires_on' => $data['certification_expires_on'] ?? null,
                ...$this->withCost($data, keep: $training),
            ])->save();
            Audit::record('training.completed', $training, ['status', 'completed_on', 'hours'], ['status' => ['from' => 'planned', 'to' => 'completed']]);

            if ($training->certification_name) {
                $cert = new EmployeeCertification;
                $cert->forceFill([
                    'team_id' => $access->team()->id,
                    'employee_id' => $training->employee_id,
                    'name' => $training->certification_name,
                    'issuer' => $training->provider,
                    'issued_on' => $training->completed_on,
                    'expires_on' => $training->certification_expires_on,
                ])->save();
                Audit::record('certification.recorded', $cert, ['name', 'issued_on', 'expires_on']);
            }
        });

        app(LearningPaths::class)->trainingCompleted($training, $request->user()); // a course on a learning path is now done

        return back()->with('status', $training->title.' completed'.($training->certification_name ? ' — '.$training->certification_name.' is on file.' : '.'));
    }

    /** HR administrators set a budget for a period, for the whole business or one department. */
    public function storeBudget(Request $request): RedirectResponse
    {
        $access = $this->manager($request);
        abort_unless($access->isAdmin(), 403);
        $data = $request->validate([
            'department' => ['nullable', 'string', 'max:80'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999999'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $budget = new TrainingBudget([...$data, 'department' => filled($data['department'] ?? null) ? trim($data['department']) : null, 'currency' => strtoupper($data['currency'])]);
        $budget->forceFill(['team_id' => $access->team()->id, 'created_by' => $request->user()->id])->save();
        Audit::record('training.budget_set', $budget, ['amount', 'starts_on', 'ends_on', 'department'], [], $access->team()->id);

        return redirect()->to(route('training.index').'#budgets')->with('status', 'Budget set: '.$budget->label().'.');
    }

    public function destroyBudget(Request $request, TrainingBudget $budget): RedirectResponse
    {
        abort_unless($this->manager($request)->isAdmin(), 403);
        Audit::record('training.budget_removed', $budget, ['amount'], [], $budget->team_id);
        $budget->delete();

        return redirect()->to(route('training.index').'#budgets')->with('status', 'Budget removed.');
    }

    /**
     * Completed training for a period as CSV: who, which course, by whom, when, hours, cost and
     * who paid. The material a skills-development report (for example to a SETA) is built from.
     */
    public function report(Request $request): StreamedResponse
    {
        $access = $this->manager($request);
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = isset($data['from']) ? Carbon::parse($data['from']) : LocalTime::today()->startOfYear();
        $to = isset($data['to']) ? Carbon::parse($data['to']) : LocalTime::today();

        $rows = TrainingRecord::query()->where('status', 'completed')
            ->whereDate('completed_on', '>=', $from)->whereDate('completed_on', '<=', $to)
            ->with('employee.position')->orderBy('completed_on')->get();
        Audit::record('training.report_exported', null, [], ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $rows->count()], $access->team()->id);

        $name = 'dot-hr-training-'.Str::slug($access->team()->name).'-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($rows) {
            echo "\u{FEFF}";
            echo InsightsExport::line(['Person', 'Position', 'Department', 'Course', 'Run by', 'Kind', 'Started', 'Completed', 'Hours', 'Cost', 'Currency', 'Paid by', 'Sponsor', 'Certificate']);
            foreach ($rows as $r) {
                echo InsightsExport::line([
                    $r->employee->fullName(), $r->employee->position?->title, $r->employee->position?->department,
                    $r->title, $r->provider, TrainingRecord::KINDS[$r->kind] ?? $r->kind,
                    $r->starts_on?->toDateString(), $r->completed_on?->toDateString(),
                    $r->hours !== null ? (float) $r->hours : null, $r->cost !== null ? (float) $r->cost : null, $r->currency,
                    TrainingRecord::PAID_BY[$r->paid_by] ?? $r->paid_by, $r->sponsor, $r->certification_name,
                ]);
            }
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    /** @var array<string, list<mixed>> */
    private const COST_RULES = [
        'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        'currency' => ['nullable', 'string', 'size:3', 'alpha'],
        'paid_by' => ['nullable', 'in:business,employee,sponsor'],
        'sponsor' => ['nullable', 'required_if:paid_by,sponsor', 'string', 'max:120'],
    ];

    /**
     * The cost fields from validated input; on an update, fields left out keep what was there.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withCost(array $data, ?TrainingRecord $keep = null): array
    {
        $cost = array_intersect_key($data, array_flip(['cost', 'currency', 'paid_by', 'sponsor']));
        if ($keep !== null) {
            $cost = array_filter($cost, fn ($v) => $v !== null && $v !== '');
        } else {
            $cost['currency'] = ($cost['currency'] ?? null) ?: 'ZAR';
            $cost['paid_by'] = ($cost['paid_by'] ?? null) ?: 'business';
        }
        if (isset($cost['currency'])) {
            $cost['currency'] = strtoupper((string) $cost['currency']);
        }
        if (($cost['paid_by'] ?? $keep?->paid_by) !== 'sponsor' && array_key_exists('paid_by', $cost)) {
            $cost['sponsor'] = null;
        }

        return $cost;
    }

    private function tell(Employee $employee, string $title, string $message): void
    {
        if ($employee->user_id) {
            User::query()->find($employee->user_id)?->notify(new LifecycleNotification($title, $message, teamId: $employee->team_id));
        }
    }

    private function manager(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->canManageWorkforce(), 403);

        return $access;
    }
}
