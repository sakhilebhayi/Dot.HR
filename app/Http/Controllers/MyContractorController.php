<?php

namespace App\Http\Controllers;

use App\Models\ContractorInvoice;
use App\Models\ContractorProfile;
use App\Models\Employee;
use App\Models\Engagement;
use App\Services\Hr\Audit;
use App\Services\Hr\EncryptedFiles;
use App\Services\Hr\Engagements;
use App\Support\Hr\HrAccess;
use App\Support\Hr\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contractor self-service (brief §12): send invoices with the file attached, open your own
 * invoice files, and keep your business details current. Acts only on the engagements of the
 * employee record linked to the signed-in account.
 */
class MyContractorController extends Controller
{
    public function __construct(private Engagements $engagements, private EncryptedFiles $files) {}

    public function submit(Request $request, Engagement $engagement): RedirectResponse
    {
        $me = $this->me($request);
        abort_unless($engagement->employee_id === $me->id, 404);

        $data = $request->validate([
            'reference' => ['required', 'string', 'max:60'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999'],
            'issued_on' => ['required', 'date', 'before_or_equal:'.LocalTime::today()->toDateString()],
            'engagement_milestone_id' => ['nullable', 'integer'],
            'file' => ['required', 'file'],
        ], ['file.required' => 'Attach the invoice (a PDF, or a photo of it).']);

        $invoice = $this->engagements->submitInvoice($engagement, $request->user(), $data, $request->file('file'), $this->files);

        return redirect()->to(route('my-work.index').'#engagement-'.$engagement->id)->with('status', 'Invoice '.$invoice->reference.' sent. HR has been told; you will see here when it is approved and paid.');
    }

    public function file(Request $request, ContractorInvoice $invoice): Response
    {
        $me = $this->me($request);
        abort_unless($invoice->engagement->employee_id === $me->id && $invoice->file_path, 404);

        return self::download($invoice, $this->files);
    }

    public function details(Request $request): RedirectResponse
    {
        $me = $this->me($request);
        abort_unless(Engagement::query()->where('employee_id', $me->id)->exists(), 404);

        $data = $request->validate([
            'trading_name' => ['nullable', 'string', 'max:160'],
            'registration_number' => ['nullable', 'string', 'max:40'],
            'vat_number' => ['nullable', 'string', 'max:20'],
            'billing_address' => ['nullable', 'string', 'max:255'],
            'invoice_email' => ['nullable', 'email', 'max:190'],
        ]);

        $profile = ContractorProfile::query()->where('employee_id', $me->id)->first() ?? new ContractorProfile;
        $profile->fill($data)->forceFill(['team_id' => $me->team_id, 'employee_id' => $me->id, 'updated_by' => $request->user()->id])->save();
        Audit::record('contractor.details_updated', $profile, Audit::changedFields($profile) ?: array_keys($data), [], $me->team_id);

        return back()->with('status', 'Your business details are saved. HR sees them with your engagement.');
    }

    /** Serve an invoice file inline (PDF or image), never cached, in a sandbox. Shared with HR's download. */
    public static function download(ContractorInvoice $invoice, EncryptedFiles $files): Response
    {
        try {
            $bytes = $files->read((string) $invoice->file_path, (string) $invoice->file_sha256);
        } catch (\Throwable $e) {
            report($e);
            abort(503, 'This invoice file cannot be opened right now.');
        }

        $name = pathinfo((string) $invoice->file_name, PATHINFO_FILENAME).'.'.(ContractorInvoice::MIMES[$invoice->file_mime] ?? 'bin');

        return response($bytes, 200, [
            'Content-Type' => (string) $invoice->file_mime,
            'Content-Disposition' => 'inline; filename="'.addcslashes($name, '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'",
        ]);
    }

    private function me(Request $request): Employee
    {
        $employee = HrAccess::current($request->user())?->employee();
        abort_if($employee === null, 404);

        return $employee;
    }
}
