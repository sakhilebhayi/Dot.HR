<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Models\TrainingRecord;
use App\Models\User;
use App\Notifications\LifecycleNotification;
use App\Services\Hr\Audit;
use App\Services\Hr\Readiness;
use App\Services\Intelligence\Insights;
use App\Support\Hr\HrAccess;
use App\Support\Hr\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

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
        ]);

        $employee = Employee::query()->findOrFail($data['employee_id']);
        $record = new TrainingRecord;
        $record->forceFill([...$data, 'team_id' => $employee->team_id, 'status' => 'planned', 'decided_by' => $request->user()->id, 'decided_at' => now()])->save();
        Audit::record('training.planned', $record, ['title', 'kind', 'starts_on']);
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
        ])->save();
        Audit::record('training.'.$to, $training, ['status', 'decision_note'], ['status' => ['from' => 'requested', 'to' => $to]]);
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
        ]);

        DB::transaction(function () use ($training, $data, $access) {
            $training->forceFill([
                'status' => 'completed',
                'completed_on' => $data['completed_on'],
                'hours' => $data['hours'] ?? null,
                'certification_name' => $data['certification_name'] ?? $training->certification_name,
                'certification_expires_on' => $data['certification_expires_on'] ?? null,
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

        return back()->with('status', $training->title.' completed'.($training->certification_name ? ' — '.$training->certification_name.' is on file.' : '.'));
    }

    private function tell(Employee $employee, string $title, string $message): void
    {
        if ($employee->user_id) {
            User::query()->find($employee->user_id)?->notify(new LifecycleNotification($title, $message));
        }
    }

    private function manager(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->canManageWorkforce(), 403);

        return $access;
    }
}
