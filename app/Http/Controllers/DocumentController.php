<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\HrDocument;
use App\Services\Hr\Audit;
use App\Services\Hr\Documents;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Employment documents and company policies (R-32 §10). HR administrators file, share and
 * withdraw them; an employee opens what concerns them, accepts what asks for acceptance, and
 * hands documents in; managers see none of it. Every read of someone else's document is audited.
 */
class DocumentController extends Controller
{
    public function __construct(private Documents $documents) {}

    /** HR files a document on an employee's record. */
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $access = $this->admin($request, $employee->team_id);

        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(HrDocument::KINDS))],
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'file' => ['required', 'file'],
            'share' => ['nullable', 'boolean'],
            'requires_acceptance' => ['nullable', 'boolean'],
        ]);

        $this->documents->store($access->team(), $employee, $request->file('file'), $data['kind'], $data['title'], $request->user(),
            shared: (bool) ($data['share'] ?? false), requiresAcceptance: (bool) ($data['requires_acceptance'] ?? false));

        return redirect()->to(route('employees.show', $employee).'#documents')->with('status', 'Filed on '.$employee->fullName().'’s record.');
    }

    public function share(Request $request, HrDocument $document): RedirectResponse
    {
        $this->admin($request, $document->team_id);
        abort_if($document->isPolicy() || $document->withdrawn_at !== null, 404);

        $this->documents->share($document, $request->boolean('requires_acceptance'));

        return back()->with('status', $document->requires_acceptance ? 'Shared, and waiting for acceptance.' : 'Shared with '.$document->employee?->fullName().'.');
    }

    public function withdraw(Request $request, HrDocument $document): RedirectResponse
    {
        $this->admin($request, $document->team_id);
        abort_if($document->withdrawn_at !== null, 404);

        $this->documents->withdraw($document, $request->user());

        return back()->with('status', 'Withdrawn. It stays on record, out of sight of the employee.');
    }

    /** Open a document: HR administrators, or the employee it concerns when it is theirs to see. */
    public function download(Request $request, HrDocument $document): Response
    {
        $access = HrAccess::forTeamId($request->user(), $document->team_id);
        abort_if($access === null, 404);

        $mine = $access->employee();
        $allowed = $access->isAdmin() || ($mine !== null && $document->visibleToEmployee($mine));
        abort_unless($allowed, 404);

        if (! $mine || $document->employee_id !== $mine->id) {
            Audit::record('document.opened', $document, [], [], $document->team_id);
        }

        try {
            $bytes = $this->documents->read($document);
        } catch (\Throwable $e) {
            report($e);
            abort(503, 'This document cannot be opened right now.');
        }

        $name = pathinfo($document->file_name, PATHINFO_FILENAME).'.'.(HrDocument::MIMES[$document->mime] ?? 'bin');

        return response($bytes, 200, [
            'Content-Type' => $document->mime,
            'Content-Disposition' => ($document->mime === 'application/pdf' || str_starts_with($document->mime, 'image/') ? 'inline' : 'attachment').'; filename="'.addcslashes($name, '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'",
        ]);
    }

    /** The employee reads a policy or accepts a document. */
    public function acknowledge(Request $request, HrDocument $document): RedirectResponse
    {
        $access = HrAccess::forTeamId($request->user(), $document->team_id);
        $mine = $access?->employee();
        abort_if($mine === null || ! $document->visibleToEmployee($mine), 404);

        $data = $request->validate(['typed_name' => [$document->isPolicy() ? 'nullable' : 'required', 'string', 'max:120'], 'confirm' => ['accepted']]);
        $this->documents->acknowledge($document, $mine, $request->user(), $data['typed_name'] ?? null);

        return back()->with('status', $document->isPolicy() ? 'Thank you — recorded that you have read it.' : 'Accepted. HR can see the time and the name you typed.');
    }

    /** The employee hands a document in to HR (a qualification, a permit, something HR asked for). */
    public function submit(Request $request): RedirectResponse
    {
        $access = HrAccess::current($request->user());
        $mine = $access?->employee();
        abort_if($mine === null, 404);

        $data = $request->validate([
            'kind' => ['required', Rule::in(HrDocument::SUBMITTABLE)],
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'file' => ['required', 'file'],
        ]);

        $this->documents->store($access->team(), $mine, $request->file('file'), $data['kind'], $data['title'], $request->user(), source: 'employee');

        return redirect()->to(route('my-work.index').'#documents')->with('status', 'Handed in. HR has been told.');
    }

    /** Company policies: HR publishes and sees who has read them; everyone else reads and confirms. */
    public function policies(Request $request): View
    {
        $access = HrAccess::current($request->user());
        abort_if($access === null || (! $access->isAdmin() && $access->employee() === null), 403);
        $mine = $access->employee();

        $policies = HrDocument::query()->whereNull('employee_id')->when(! $access->isAdmin(), fn ($q) => $q->live())
            ->withCount('acknowledgements')->latest()->get();

        return view('documents.policies', [
            'policies' => $policies,
            'admin' => $access->isAdmin(),
            'linked' => $access->isAdmin() ? Employee::query()->whereNotNull('user_id')->where('status', '!=', 'terminated')->count() : 0,
            'mine' => $mine ? $mine->documentAcknowledgements()->pluck('acknowledged_at', 'hr_document_id') : collect(),
        ]);
    }

    public function storePolicy(Request $request): RedirectResponse
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->isAdmin(), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'file' => ['required', 'file'],
            'requires_acceptance' => ['nullable', 'boolean'],
        ]);

        $this->documents->store($access->team(), null, $request->file('file'), HrDocument::POLICY, $data['title'], $request->user(),
            requiresAcceptance: (bool) ($data['requires_acceptance'] ?? false));

        return redirect()->route('policies.index')->with('status', 'Published. Everyone with a linked record has been told.');
    }

    private function admin(Request $request, int $teamId): HrAccess
    {
        $access = HrAccess::forTeamId($request->user(), $teamId);
        abort_unless($access?->isAdmin(), 403);

        return $access;
    }
}
