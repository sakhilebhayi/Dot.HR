<?php

namespace App\Http\Controllers;

use App\Models\PayrollRun;
use App\Services\Hr\Audit;
use App\Services\Hr\PayrollInputs;
use App\Services\Intelligence\InsightsExport;
use App\Support\Hr\HrAccess;
use App\Support\Hr\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payroll inputs (brief §11): the period's starters, leavers, approved hours and leave, ready for
 * the payroll provider, and HR's sign-off of them. HR administrators only: it is pay-adjacent.
 */
class PayrollController extends Controller
{
    public function __construct(private PayrollInputs $inputs) {}

    public function index(Request $request): View
    {
        $this->admin($request);
        [$from, $to] = $this->period($request);
        $rows = $this->inputs->rows($from, $to);
        $latest = $this->runs($from, $to)->first();

        return view('payroll.index', [
            'from' => $from,
            'to' => $to,
            'month' => $request->filled('from') ? null : $from->format('Y-m'),
            'rows' => $rows,
            'checks' => $this->inputs->checks($from, $to),
            'latest' => $latest,
            'changed' => $latest !== null && $latest->inputs_hash !== $this->inputs->fingerprint($this->inputs->table($rows)),
            'recent' => PayrollRun::query()->with('signer')->latest('signed_off_at')->limit(6)->get(),
            'ordinaryHours' => HrAccess::current($request->user())?->team()->weekly_ordinary_hours,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $access = $this->admin($request);
        [$from, $to] = $this->period($request);
        $table = $this->inputs->table($this->inputs->rows($from, $to));
        Audit::record('payroll.inputs_exported', null, [], ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => count($table)], $access->team()->id);

        $name = 'dot-hr-payroll-inputs-'.Str::slug($access->team()->name).'-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($table) {
            echo "\u{FEFF}";
            echo InsightsExport::line(PayrollInputs::HEADER);
            foreach ($table as $row) {
                echo InsightsExport::line($row);
            }
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    /** HR signs the period's inputs off once nothing in it is still waiting for a decision. */
    public function signOff(Request $request): RedirectResponse
    {
        $access = $this->admin($request);
        [$from, $to] = $this->period($request);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        $checks = $this->inputs->checks($from, $to);
        if (array_sum($checks) > 0) {
            throw ValidationException::withMessages(['period' => 'Some timesheets, leave or clock-ins in this period are still open. Settle them first, so the figures you sign off are final.']);
        }

        $table = $this->inputs->table($this->inputs->rows($from, $to));
        $run = new PayrollRun;
        $run->forceFill([
            'team_id' => $access->team()->id, 'period_start' => $from->toDateString(), 'period_end' => $to->toDateString(),
            'people' => count($table), 'inputs_hash' => $this->inputs->fingerprint($table),
            'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
            'signed_off_by' => $request->user()->id, 'signed_off_at' => now(),
        ])->save();
        Audit::record('payroll.signed_off', $run, ['period_start', 'period_end', 'people'], [], $access->team()->id);

        // The page's own sign-off notice confirms it; no second message.
        return redirect()->route('payroll.index', $this->query($request));
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'required_with:to', 'date'],
            'to' => ['nullable', 'required_with:from', 'date', 'after_or_equal:from'],
        ]);
        if (isset($data['from'], $data['to'])) {
            $from = CarbonImmutable::parse($data['from'])->startOfDay();
            $to = CarbonImmutable::parse($data['to'])->startOfDay();
            if ($from->diffInDays($to) > 62) {
                throw ValidationException::withMessages(['to' => 'Choose a period of at most two months.']);
            }

            return [$from, $to];
        }
        $month = isset($data['month']) ? CarbonImmutable::createFromFormat('!Y-m', $data['month']) : CarbonImmutable::parse(LocalTime::today()->toDateString());

        return [$month->startOfMonth(), $month->endOfMonth()->startOfDay()];
    }

    /** @return array<string, string> */
    private function query(Request $request): array
    {
        return array_filter($request->only(['month', 'from', 'to']), fn ($v) => filled($v));
    }

    /** @return Collection<int, PayrollRun> */
    private function runs(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return PayrollRun::query()->whereDate('period_start', $from)->whereDate('period_end', $to)->with('signer')->latest('signed_off_at')->get();
    }

    private function admin(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->isAdmin(), 403);

        return $access;
    }
}
