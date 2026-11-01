<?php

namespace App\Http\Controllers;

use App\Models\ExitInterview;
use App\Models\Resignation;
use App\Models\ServiceCertificate;
use App\Services\Hr\Leaving;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Leaving, the person's side: give notice in writing, ask to withdraw it, answer an exit
 * interview if they want to, and open their certificate of service. Everything acts on the
 * record linked to the signed-in account.
 */
class MyLeavingController extends Controller
{
    public function __construct(private Leaving $leaving) {}

    public function resign(Request $request): RedirectResponse
    {
        $employee = HrAccess::current($request->user())?->employee();
        abort_if($employee === null, 404);

        $data = $request->validate([
            'last_day' => ['required', 'date'],
            'reason' => ['nullable', Rule::in(array_keys(Resignation::REASONS))],
            'message' => ['nullable', 'string', 'max:5000'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Tick the box to confirm you are resigning.']);

        $resignation = $this->leaving->resign($employee, $request->user(), Carbon::parse($data['last_day']), $data['reason'] ?? null, $data['message'] ?? null);

        return redirect()->to(route('my-work.index').'#leaving')->with('status', 'Your notice is with HR ('.$resignation->reference().'). You will be told when they have received it.');
    }

    public function withdraw(Request $request, Resignation $resignation): RedirectResponse
    {
        $this->mine($request, $resignation->team_id, $resignation->employee_id);
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:2000']]);
        $this->leaving->requestWithdrawal($resignation, $data['note']);

        return back()->with('status', 'Asked. Withdrawing needs HR to agree; you will be told.');
    }

    public function interview(Request $request, ExitInterview $interview): View
    {
        $this->mine($request, $interview->team_id, $interview->employee_id);

        return view('my-work.exit-interview', ['interview' => $interview]);
    }

    public function answer(Request $request, ExitInterview $interview): RedirectResponse
    {
        $this->mine($request, $interview->team_id, $interview->employee_id);
        $data = $request->validate([
            'reasons' => ['nullable', 'array'], 'reasons.*' => [Rule::in(array_keys(ExitInterview::REASONS))],
            'answers' => ['nullable', 'array'], 'answers.*' => ['nullable', 'string', 'max:5000'],
            'would_return' => ['nullable', Rule::in(array_keys(ExitInterview::RETURN))],
            'share_anonymously' => ['nullable', 'boolean'],
        ]);

        $this->leaving->completeInterview($interview, 'form', $data['reasons'] ?? [], $data['answers'] ?? [], $data['would_return'] ?? null, (bool) ($data['share_anonymously'] ?? false));

        return redirect()->to(route('my-work.index').'#leaving')->with('status', 'Thank you. Only HR administrators read your answers.');
    }

    public function decline(Request $request, ExitInterview $interview): RedirectResponse
    {
        $this->mine($request, $interview->team_id, $interview->employee_id);
        $this->leaving->declineInterview($interview);

        return redirect()->to(route('my-work.index').'#leaving')->with('status', 'No problem. Nothing more will be asked.');
    }

    public function certificate(Request $request, ServiceCertificate $certificate): View
    {
        $this->mine($request, $certificate->team_id, $certificate->employee_id);

        return view('leaving.certificate', ['certificate' => $certificate->load('employee'), 'back' => route('my-work.index').'#leaving']);
    }

    private function mine(Request $request, int $teamId, int $employeeId): void
    {
        abort_unless(HrAccess::forTeamId($request->user(), $teamId)?->employee()?->id === $employeeId, 404);
    }
}
