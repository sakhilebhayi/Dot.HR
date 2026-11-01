<?php

namespace App\Http\Controllers;

use App\Models\ContractorInvoice;
use App\Models\ContractorProfile;
use App\Models\Employee;
use App\Models\Engagement;
use App\Models\EngagementMilestone;
use App\Models\User;
use App\Notifications\LifecycleNotification;
use App\Services\Hr\Audit;
use App\Services\Hr\EncryptedFiles;
use App\Services\Hr\Engagements;
use App\Support\Hr\HrAccess;
use App\Support\Hr\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contractors and other external workers (R-32 Phase 6b, brief §12), for HR administrators
 * only: rates and invoices are commercial terms the rest of the business does not see.
 */
class ContractorController extends Controller
{
    public function __construct(private Engagements $engagements) {}

    public function index(Request $request): View
    {
        $this->admin($request);

        $active = Engagement::query()->where('status', 'active')->with('employee')->orderByRaw('ends_on is null')->orderBy('ends_on')->get();

        return view('contractors.index', [
            'active' => $active,
            'endingSoon' => $active->filter(fn (Engagement $e) => $e->endsSoon())->values(),
            'delivered' => EngagementMilestone::query()->where('status', 'submitted')->with('engagement.employee')->orderBy('submitted_at')->get(),
            'invoicesOpen' => ContractorInvoice::query()->whereIn('status', ['received', 'disputed', 'approved'])->with('engagement.employee')->orderBy('received_on')->get(),
            'ended' => Engagement::query()->where('status', 'ended')->with('employee')->latest('ended_on')->limit(15)->get(),
            'contractors' => Employee::query()->where('employment_type', 'contractor')->where('status', '!=', 'terminated')->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $access = $this->admin($request);

        $data = $request->validate([
            'employee_id' => ['nullable', 'integer', 'required_without:first_name'],
            'first_name' => ['nullable', 'string', 'max:80', 'required_without:employee_id'],
            'last_name' => ['nullable', 'string', 'max:80', 'required_with:first_name'],
            'email' => ['nullable', 'email', 'max:190'],
            'kind' => ['required', Rule::in(array_keys(Engagement::KINDS))],
            'title' => ['required', 'string', 'max:160'],
            'scope' => ['nullable', 'string', 'max:5000'],
            'rate_basis' => ['required', Rule::in(array_keys(Engagement::RATE_BASES))],
            'rate_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ], ['employee_id.required_without' => 'Pick the contractor, or add a new one.']);

        try {
            $engagement = DB::transaction(function () use ($data, $access, $request) {
                $person = isset($data['employee_id'])
                    ? Employee::query()->findOrFail($data['employee_id'])
                    : $this->newContractor($data, $access->team()->id, $request->user()->id);

                return $this->engagements->create($person, $data, $request->user()->id);
            });
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('contractors.show', $engagement)->with('status', 'Engagement started with '.$engagement->employee->fullName().'.');
    }

    public function show(Request $request, Engagement $engagement): View
    {
        $this->admin($request);

        return view('contractors.show', [
            'engagement' => $engagement->load(['employee', 'milestones', 'invoices.milestone']),
            'profile' => ContractorProfile::query()->where('employee_id', $engagement->employee_id)->first(),
            'signals' => $engagement->signals(),
            'history' => Engagement::query()->where('employee_id', $engagement->employee_id)->whereKeyNot($engagement->id)->latest('starts_on')->get(),
        ]);
    }

    public function renew(Request $request, Engagement $engagement): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate(['ends_on' => ['required', 'date']]);

        return $this->attempt(fn () => $this->engagements->renew($engagement, $data['ends_on']), 'Renewed to '.date('j M Y', (int) strtotime($data['ends_on'])).'.');
    }

    public function end(Request $request, Engagement $engagement): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate([
            'end_reason' => ['required', Rule::in(array_keys(Engagement::END_REASONS))],
            'ended_on' => ['nullable', 'date'],
        ]);

        return $this->attempt(fn () => $this->engagements->end($engagement, $data['end_reason'], $data['ended_on'] ?? null), 'Engagement ended.');
    }

    public function addMilestone(Request $request, Engagement $engagement): RedirectResponse
    {
        $this->admin($request);
        abort_unless($engagement->status === 'active', 422, 'This engagement has ended.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'due_on' => ['nullable', 'date'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ]);

        $milestone = new EngagementMilestone;
        $milestone->forceFill([
            'team_id' => $engagement->team_id,
            'engagement_id' => $engagement->id,
            'title' => $data['title'],
            'due_on' => $data['due_on'] ?? null,
            'amount' => $data['amount'] ?? null,
            'status' => 'open',
        ])->save();
        Audit::record('milestone.created', $milestone, ['title', 'due_on', 'amount']);

        return back()->with('status', 'Milestone added.');
    }

    public function decideMilestone(Request $request, EngagementMilestone $milestone, string $decision): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate(['note' => [$decision === 'reject' ? 'required' : 'nullable', 'string', 'max:255']], ['note.required' => 'Say what still needs doing.']);

        try {
            $this->engagements->decide($milestone, $decision === 'approve', $data['note'] ?? null, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->notifyContractor($milestone->engagement, $decision === 'approve' ? 'Milestone approved' : 'Milestone sent back',
            '"'.$milestone->title.'"'.($decision === 'approve' ? ' is approved.' : ' needs more work: '.$data['note']));

        return back()->with('status', '"'.$milestone->title.'" '.($decision === 'approve' ? 'approved.' : 'sent back.'));
    }

    public function addInvoice(Request $request, Engagement $engagement): RedirectResponse
    {
        $this->admin($request);

        $data = $request->validate([
            'reference' => ['required', 'string', 'max:60', Rule::unique('contractor_invoices')->where('engagement_id', $engagement->id)],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'received_on' => ['required', 'date', 'before_or_equal:'.LocalTime::today()->toDateString()],
        ], ['reference.unique' => 'That invoice reference is already recorded for this engagement.']);

        $invoice = new ContractorInvoice;
        $invoice->forceFill([
            'team_id' => $engagement->team_id,
            'engagement_id' => $engagement->id,
            'reference' => $data['reference'],
            'amount' => $data['amount'],
            'received_on' => $data['received_on'],
            'status' => 'received',
        ])->save();
        Audit::record('invoice.received', $invoice, ['reference', 'amount', 'received_on']);

        return back()->with('status', 'Invoice '.$invoice->reference.' recorded.');
    }

    public function moveInvoice(Request $request, ContractorInvoice $invoice): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(ContractorInvoice::STATUSES))],
            'note' => [$request->input('status') === 'disputed' ? 'required' : 'nullable', 'string', 'max:255'],
        ], ['note.required' => 'Say what is disputed.']);

        try {
            $this->engagements->move($invoice, $data['status'], $data['note'] ?? null, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $label = strtolower(ContractorInvoice::STATUSES[$data['status']]);
        $this->notifyContractor($invoice->engagement, 'Invoice '.$invoice->reference,
            'Your invoice '.$invoice->reference.' is '.$label.($data['status'] === 'disputed' ? ': '.$data['note'] : '.'));

        return back()->with('status', 'Invoice '.$invoice->reference.' marked '.$label.'.');
    }

    /** HR opens an invoice file the contractor sent. Audited, like opening someone's document. */
    public function invoiceFile(Request $request, ContractorInvoice $invoice, EncryptedFiles $files): Response
    {
        $this->admin($request);
        abort_unless($invoice->file_path !== null, 404);
        Audit::record('invoice.file_opened', $invoice, [], [], $invoice->team_id);

        return MyContractorController::download($invoice, $files);
    }

    /** @param array<string, mixed> $data */
    private function newContractor(array $data, int $teamId, int $actorId): Employee
    {
        $person = new Employee;
        $person->forceFill([
            'team_id' => $teamId,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'] ?? null,
            'employment_type' => 'contractor',
            'status' => 'active',
            'start_date' => $data['starts_on'],
            'created_by' => $actorId,
        ])->save();
        Audit::record('employee.created', $person, ['first_name', 'last_name', 'email', 'employment_type', 'status', 'start_date']);

        return $person;
    }

    private function notifyContractor(Engagement $engagement, string $title, string $message): void
    {
        $userId = $engagement->employee->user_id;
        if ($userId) {
            User::query()->find($userId)?->notify(new LifecycleNotification($title, $message, teamId: $engagement->team_id));
        }
    }

    private function attempt(callable $action, string $ok): RedirectResponse
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $ok);
    }

    private function admin(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->isAdmin(), 403);

        return $access;
    }
}
