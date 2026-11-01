<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\HrCase;
use App\Models\HrCaseEntry;
use App\Models\User;
use App\Services\Hr\Audit;
use App\Services\Hr\Cases;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The employee relations desk (R-32 brief §15): grievances, disciplinary cases and workplace
 * incidents. Open to HR administrators and to members granted employee relations, who see the
 * cases assigned to them; never to managers. A case anyone may not open answers 404, so its
 * existence is not confirmed. Every view of a case is audited.
 */
class CaseController extends Controller
{
    public function __construct(private Cases $cases) {}

    public function index(Request $request): View
    {
        $access = $this->desk($request);
        $state = $request->query('state') === 'closed' ? 'closed' : 'open';
        $type = array_key_exists((string) $request->query('type'), HrCase::TYPES) ? (string) $request->query('type') : null;

        $cases = $access->visibleCases()
            ->when($state === 'open', fn ($q) => $q->whereNotIn('status', ['closed', 'withdrawn']), fn ($q) => $q->whereIn('status', ['closed', 'withdrawn']))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->with(['employee', 'handler'])
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'appealed' THEN 1 WHEN 'hearing' THEN 2 WHEN 'investigating' THEN 3 ELSE 4 END")
            ->latest()->get();

        return view('cases.index', [
            'cases' => $cases,
            'state' => $state,
            'type' => $type,
            'counts' => $access->visibleCases()->whereNotIn('status', ['closed', 'withdrawn'])
                ->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type'),
            'dueReports' => $access->visibleCases()->where('type', 'incident')->where('category', 'injury')
                ->whereNull('reported_externally_on')->whereNotIn('status', ['withdrawn'])->count(),
        ]);
    }

    public function create(Request $request): View
    {
        $access = $this->desk($request);
        $type = array_key_exists((string) $request->query('type'), HrCase::TYPES) ? (string) $request->query('type') : 'disciplinary';

        return view('cases.create', [
            'type' => $type,
            'employees' => Employee::query()->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'status']),
            'handlers' => $access->isAdmin() ? $this->cases->handlersFor($access->team()) : collect([$request->user()]),
            'admin' => $access->isAdmin(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $access = $this->desk($request);
        $teamId = $access->team()->id;
        $type = (string) $request->input('type');

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(HrCase::TYPES))],
            'category' => ['required', Rule::in(array_keys(HrCase::CATEGORIES[$type] ?? []))],
            'employee_id' => [$type === 'incident' ? 'nullable' : 'required', Rule::exists('employees', 'id')->where('team_id', $teamId)],
            'respondent_employee_id' => ['nullable', Rule::prohibitedIf($type !== 'grievance'), 'different:employee_id', Rule::exists('employees', 'id')->where('team_id', $teamId)],
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'summary' => ['required', 'string', 'min:10', 'max:10000'],
            'occurred_on' => ['nullable', 'date', 'before_or_equal:today'],
            'handler_user_id' => ['nullable', 'integer'],
            'restricted' => ['nullable', 'boolean'],
        ]);

        // An employee relations holder always handles what they open, or they could not see it.
        $handlerId = $access->isAdmin() ? ($data['handler_user_id'] ?? $request->user()->id) : $request->user()->id;
        $handler = User::query()->find($handlerId);
        abort_if($handler === null || ! HrAccess::forTeamId($handler, $teamId)?->canHandleCases(), 422, 'Choose someone who may handle cases.');

        $case = $this->cases->open($access->team(), [...$data, 'handler_user_id' => $handler->id], $request->user());

        return redirect()->route('cases.show', $case)->with('status', $case->reference().' opened.');
    }

    public function show(Request $request, HrCase $case): View
    {
        $access = $this->see($request, $case);
        Audit::record('case.viewed', $case, [], [], $case->team_id);

        $history = $case->employee_id
            ? $access->visibleCases()->where('employee_id', $case->employee_id)->whereKeyNot($case->id)->latest()->get()
            : collect();

        return view('cases.show', [
            'case' => $case->load(['entries.author', 'employee', 'respondent', 'handler']),
            'admin' => $access->isAdmin(),
            'handlers' => $access->isAdmin() ? $this->cases->handlersFor($access->team()) : collect(),
            'history' => $history,
            'activeWarnings' => $history->filter(fn (HrCase $c) => $c->warningActive()),
        ]);
    }

    public function entry(Request $request, HrCase $case): RedirectResponse
    {
        $this->see($request, $case);
        $data = $request->validate([
            'kind' => ['required', Rule::in(HrCaseEntry::WRITABLE)],
            'body' => ['required', 'string', 'max:10000'],
            'share' => ['nullable', 'boolean'],
        ]);

        $this->cases->addEntry($case, $data['kind'], $data['body'], (bool) ($data['share'] ?? false), $request->user());

        return $this->back($case, ($data['share'] ?? false) ? 'Added and shared. They have been told.' : 'Added to the internal record.');
    }

    public function investigate(Request $request, HrCase $case): RedirectResponse
    {
        $this->see($request, $case);
        $this->cases->startInvestigation($case, $request->user());

        return $this->back($case, 'Investigation started.');
    }

    public function hearing(Request $request, HrCase $case): RedirectResponse
    {
        $this->see($request, $case);
        $data = $request->validate([
            'hearing_at' => ['required', 'date'],
            'hearing_place' => ['required', 'string', 'max:160'],
            'hearing_chair' => ['nullable', 'string', 'max:120'],
            'points' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $caution = $this->cases->setHearing($case, Carbon::parse($data['hearing_at']), $data['hearing_place'], $data['hearing_chair'] ?? null, $data['points'], $request->user());

        return $this->back($case, 'Hearing set and the notice sent.'.($caution ? ' '.$caution : ''));
    }

    public function decide(Request $request, HrCase $case): RedirectResponse
    {
        $this->see($request, $case);
        $data = $request->validate([
            'outcome' => ['required', Rule::in(array_keys(HrCase::OUTCOMES[$case->type] ?? []))],
            'reasons' => ['required', 'string', 'min:10', 'max:10000'],
            'share' => ['nullable', 'boolean'],
            'warning_months' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);

        $this->cases->decide($case, $data['outcome'], $data['reasons'], (bool) ($data['share'] ?? false), isset($data['warning_months']) ? (int) $data['warning_months'] : null, $request->user());

        return $this->back($case, 'Outcome recorded.'.(($data['share'] ?? false) ? ' They have been told.' : ' Not shared yet.'));
    }

    public function appeal(Request $request, HrCase $case): RedirectResponse
    {
        $this->see($request, $case);
        $data = $request->validate(['grounds' => ['required', 'string', 'min:10', 'max:5000']]);
        $this->cases->appeal($case, $data['grounds'], $request->user(), byEmployee: false);

        return $this->back($case, 'Appeal recorded.');
    }

    public function appealDecision(Request $request, HrCase $case): RedirectResponse
    {
        $this->see($request, $case);
        $data = $request->validate([
            'appeal_outcome' => ['required', Rule::in(array_keys(HrCase::APPEAL_OUTCOMES))],
            'reasons' => ['required', 'string', 'min:10', 'max:10000'],
        ]);
        $this->cases->decideAppeal($case, $data['appeal_outcome'], $data['reasons'], $request->user());

        return $this->back($case, 'Appeal decided and shared.');
    }

    public function close(Request $request, HrCase $case): RedirectResponse
    {
        $this->see($request, $case);
        $this->cases->close($case, $request->user());

        return $this->back($case, 'Case closed.');
    }

    public function withdraw(Request $request, HrCase $case): RedirectResponse
    {
        $this->see($request, $case);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $this->cases->withdraw($case, $data['reason'], $request->user());

        return $this->back($case, 'Case withdrawn.');
    }

    public function assign(Request $request, HrCase $case): RedirectResponse
    {
        $access = $this->see($request, $case);
        abort_unless($access->isAdmin(), 403);
        $data = $request->validate(['handler_user_id' => ['required', 'integer'], 'restricted' => ['nullable', 'boolean']]);

        $handler = User::query()->find($data['handler_user_id']);
        abort_if($handler === null, 422);
        $this->cases->assign($case, $handler, (bool) ($data['restricted'] ?? false), $request->user());

        // A restricted case the assigner is no longer part of closes to them.
        return HrAccess::forTeamId($request->user(), $case->team_id)?->canSeeCase($case->fresh())
            ? $this->back($case, 'Handler and access saved.')
            : redirect()->route('cases.index')->with('status', $case->reference().' is now restricted to '.$handler->name.' and the owner.');
    }

    public function reported(Request $request, HrCase $case): RedirectResponse
    {
        $this->see($request, $case);
        $data = $request->validate(['reported_on' => ['required', 'date', 'before_or_equal:today']]);
        $this->cases->markReported($case, Carbon::parse($data['reported_on']), $request->user());

        return $this->back($case, 'Recorded as reported.');
    }

    private function desk(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->canHandleCases(), 403);

        return $access;
    }

    private function see(Request $request, HrCase $case): HrAccess
    {
        $access = HrAccess::forTeamId($request->user(), $case->team_id);
        abort_unless($access?->canSeeCase($case), 404);

        return $access;
    }

    private function back(HrCase $case, string $status): RedirectResponse
    {
        return redirect()->route('cases.show', $case)->with('status', $status);
    }
}
