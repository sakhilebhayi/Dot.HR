<?php

namespace App\Http\Controllers;

use App\Models\HrCase;
use App\Models\HrCaseEntry;
use App\Services\Hr\Cases;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The person's side of an HR case (R-32 brief §15): they lodge a grievance from My work, read
 * what HR shared with them, confirm they received a notice, reply in writing and appeal an
 * outcome. They never see HR's internal entries, and nothing here takes a case id they could
 * use to reach someone else's case: each one is checked against their own linked record.
 */
class MyCaseController extends Controller
{
    public function __construct(private Cases $cases) {}

    public function lodge(Request $request): RedirectResponse
    {
        $access = $this->mine($request);

        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(HrCase::CATEGORIES['grievance']))],
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'summary' => ['required', 'string', 'min:10', 'max:10000'],
            'about' => ['nullable', 'string', 'max:160'],
            'occurred_on' => ['nullable', 'date', 'before_or_equal:today'],
            'owner_only' => ['nullable', 'boolean'],
        ]);

        // Who it is about stays in the encrypted account; HR links a record if it needs to.
        $summary = trim($data['summary']).(filled($data['about'] ?? null) ? "\n\nWho it is about: ".trim($data['about']) : '');

        $case = $this->cases->open($access->team(), [
            'type' => 'grievance',
            'category' => $data['category'],
            'title' => $data['title'],
            'summary' => $summary,
            'occurred_on' => $data['occurred_on'] ?? null,
            'employee_id' => $access->employee()?->id,
            // About someone in HR: only the business owner receives it.
            'restricted' => (bool) ($data['owner_only'] ?? false),
        ], $request->user(), source: 'employee');

        return redirect()->route('my-work.cases.show', $case)->with('status', 'Lodged as '.$case->reference().'. '.($case->restricted ? 'Only the business owner has been told.' : 'HR has been told.'));
    }

    public function show(Request $request, HrCase $case): View
    {
        $this->own($request, $case);

        return view('my-work.case', [
            'case' => $case,
            'entries' => $case->entries()->where('shared', true)->get(),
        ]);
    }

    public function reply(Request $request, HrCase $case): RedirectResponse
    {
        $this->own($request, $case);
        abort_unless($case->isOpen(), 404);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:10000']]);

        $this->cases->addEntry($case, 'response', $data['body'], true, $request->user(), byEmployee: true);

        return back()->with('status', 'Sent to HR.');
    }

    public function received(Request $request, HrCaseEntry $entry): RedirectResponse
    {
        $case = $entry->case;
        $this->own($request, $case);
        abort_unless($entry->shared && $entry->kind === 'notice', 404);

        $this->cases->confirmReceipt($entry);

        return back()->with('status', 'Thank you. HR can see that you received it.');
    }

    public function appeal(Request $request, HrCase $case): RedirectResponse
    {
        $this->own($request, $case);
        $data = $request->validate(['grounds' => ['required', 'string', 'min:10', 'max:5000']]);

        $this->cases->appeal($case, $data['grounds'], $request->user(), byEmployee: true);

        return back()->with('status', 'Appeal lodged. HR has been told.');
    }

    private function mine(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_if($access?->employee() === null, 404);

        return $access;
    }

    private function own(Request $request, HrCase $case): void
    {
        $access = HrAccess::forTeamId($request->user(), $case->team_id);
        abort_unless($access?->canSeeOwnCase($case), 404);
    }
}
