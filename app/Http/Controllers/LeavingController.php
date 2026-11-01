<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\ExitInterview;
use App\Models\LifecycleChecklist;
use App\Models\Resignation;
use App\Models\ServiceCertificate;
use App\Services\Hr\Audit;
use App\Services\Hr\Leaving;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Leaving, HR's side (brief §15): resignations to acknowledge, requests to withdraw, exit
 * interviews and certificates of service. HR administrators only; managers see none of it.
 */
class LeavingController extends Controller
{
    public function __construct(private Leaving $leaving) {}

    public function index(Request $request): View
    {
        $access = $this->admin($request);

        return view('leaving.index', [
            'waiting' => Resignation::query()->where(fn ($q) => $q->where('status', 'given')
                ->orWhere(fn ($q) => $q->whereNotNull('withdrawal_requested_at')->whereNull('withdrawal_decided_at')))
                ->with('employee')->oldest('given_at')->get(),
            // Notice received, leaving soon or left in the last month.
            'leaving' => Resignation::query()->where('status', 'acknowledged')
                ->where(fn ($q) => $q->whereDate('agreed_last_day', '>=', today()->subDays(30))
                    ->orWhere(fn ($q) => $q->whereNull('agreed_last_day')->whereDate('last_day', '>=', today()->subDays(30))))
                ->with('employee')->get()
                ->sortBy(fn (Resignation $r) => $r->leavingOn())->values(),
            'interviews' => ExitInterview::query()->with('employee')->latest('offered_at')->limit(20)->get(),
            'patterns' => $this->leaving->patterns($access->team()->id),
            'certificates' => ServiceCertificate::query()->with('employee')->latest('issued_at')->limit(10)->get(),
        ]);
    }

    public function acknowledge(Request $request, Resignation $resignation): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate(['agreed_last_day' => ['nullable', 'date', 'after_or_equal:today'], 'offer_interview' => ['nullable', 'boolean']]);

        $this->leaving->acknowledge($resignation, isset($data['agreed_last_day']) ? Carbon::parse($data['agreed_last_day']) : null, (bool) ($data['offer_interview'] ?? false), $request->user());

        return redirect()->route('lifecycle.show', $resignation->fresh()->lifecycle_checklist_id)
            ->with('status', 'Resignation acknowledged. Offboarding started for '.$resignation->employee->fullName().', last day '.$resignation->fresh()->leavingOn()->format('j M Y').'.');
    }

    public function withdrawal(Request $request, Resignation $resignation, string $decision): RedirectResponse
    {
        $this->admin($request);
        abort_unless(in_array($decision, ['agree', 'decline'], true), 404);
        $this->leaving->decideWithdrawal($resignation, $decision === 'agree', $request->user());

        return back()->with('status', $decision === 'agree' ? 'Withdrawn by agreement. Their offboarding checklist was cancelled.' : 'They have been told the resignation stands.');
    }

    public function offerInterview(Request $request, Employee $employee): RedirectResponse
    {
        $this->admin($request);
        $this->leaving->offerInterview($employee, $request->user());

        return back()->with('status', 'Exit interview offered. '.$employee->first_name.' can answer from My work, or you can record a conversation.');
    }

    public function interview(Request $request, ExitInterview $interview): View
    {
        $this->admin($request);
        Audit::record('exit_interview.viewed', $interview, [], [], $interview->team_id);

        return view('leaving.interview', ['interview' => $interview->load('employee')]);
    }

    /** HR records an exit conversation held in person, with the person's choice about sharing. */
    public function recordInterview(Request $request, ExitInterview $interview): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate([
            'reasons' => ['nullable', 'array'], 'reasons.*' => [Rule::in(array_keys(ExitInterview::REASONS))],
            'notes' => ['required', 'string', 'min:10', 'max:10000'],
            'would_return' => ['nullable', Rule::in(array_keys(ExitInterview::RETURN))],
            'share_anonymously' => ['nullable', 'boolean'],
        ]);

        $this->leaving->completeInterview($interview, 'meeting', $data['reasons'] ?? [], ['notes' => $data['notes']], $data['would_return'] ?? null, (bool) ($data['share_anonymously'] ?? false));

        return back()->with('status', 'Exit conversation recorded.');
    }

    public function certificateForm(Request $request, Employee $employee): View
    {
        $access = $this->admin($request);
        $checklist = LifecycleChecklist::query()->where('employee_id', $employee->id)->where('process', 'offboarding')->latest('id')->first();
        $previous = ServiceCertificate::query()->latest('issued_at')->first();

        return view('leaving.certificate-form', [
            'employee' => $employee->load('position'),
            'defaults' => [
                'employer_name' => $previous->employer_name ?? $access->team()->name,
                'employer_address' => $previous?->employer_address,
                'job_title' => $employee->position?->title,
                'start_date' => $employee->start_date?->toDateString(),
                'end_date' => ($employee->end_date ?? $checklist?->last_day)?->toDateString(),
                'reason' => $checklist?->reason ? LifecycleChecklist::REASONS[$checklist->reason] ?? null : null,
            ],
        ]);
    }

    public function issueCertificate(Request $request, Employee $employee): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate([
            'employer_name' => ['required', 'string', 'max:160'],
            'employer_address' => ['required', 'string', 'max:255'],
            'job_title' => ['required', 'string', 'max:160'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'remuneration' => ['required', 'string', 'max:255'],
            'reason_requested' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'required_if:reason_requested,1', 'string', 'max:255'],
        ], ['reason.required_if' => 'They asked for the reason, so state it.']);

        $certificate = $this->leaving->issueCertificate($employee, [
            ...collect($data)->except(['reason_requested', 'reason'])->all(),
            'reason' => ($data['reason_requested'] ?? false) ? $data['reason'] : null,
        ], $request->user());

        return redirect()->route('leaving.certificate', $certificate)->with('status', 'Issued. '.$employee->first_name.' can open it from My work.');
    }

    public function certificate(Request $request, ServiceCertificate $certificate): View
    {
        $this->admin($request);

        return view('leaving.certificate', ['certificate' => $certificate->load('employee'), 'back' => route('employees.show', $certificate->employee_id)]);
    }

    private function admin(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->isAdmin(), 403);

        return $access;
    }
}
