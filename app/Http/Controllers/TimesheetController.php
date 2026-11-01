<?php

namespace App\Http\Controllers;

use App\Models\Timesheet;
use App\Models\User;
use App\Notifications\LifecycleNotification;
use App\Services\Hr\Workforce;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Timesheet approvals (R-32 Phase 6a) for HR administrators and supervisors, plus the
 * business's ordinary weekly hours (HR administrators only).
 */
class TimesheetController extends Controller
{
    public function __construct(private Workforce $workforce) {}

    public function index(Request $request): View
    {
        $access = $this->supervisor($request);

        return view('timesheets.index', [
            'waiting' => Timesheet::query()->where('status', 'submitted')->with('employee')->orderBy('week_start')->get(),
            'recent' => Timesheet::query()->whereIn('status', ['approved', 'rejected'])->with(['employee', 'decider'])->latest('decided_at')->limit(20)->get(),
            'ordinaryHours' => $access->team()->weekly_ordinary_hours,
            'isAdmin' => $access->isAdmin(),
        ]);
    }

    public function decide(Request $request, Timesheet $timesheet, string $decision): RedirectResponse
    {
        $this->supervisor($request);
        abort_unless(in_array($decision, ['approve', 'reject'], true), 404);
        $data = $request->validate(['note' => [$decision === 'reject' ? 'required' : 'nullable', 'string', 'max:255']], ['note.required' => 'Say why it is sent back.']);

        if ($timesheet->employee->user_id === $request->user()->id) {
            return back()->with('error', 'Someone else has to decide your own timesheet.');
        }

        try {
            $this->workforce->decide($timesheet, $decision === 'approve', $data['note'] ?? null, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $person = $timesheet->employee->user_id ? User::query()->find($timesheet->employee->user_id) : null;
        $person?->notify(new LifecycleNotification(
            $decision === 'approve' ? 'Timesheet approved' : 'Timesheet sent back',
            'Your week of '.$timesheet->week_start->format('j M').($decision === 'approve' ? ' is approved.' : ' needs another look: '.$data['note']),
            teamId: $timesheet->team_id,
        ));

        return back()->with('status', $timesheet->employee->fullName().'\'s week '.($decision === 'approve' ? 'approved.' : 'sent back.'));
    }

    public function settings(Request $request): RedirectResponse
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->isAdmin(), 403);

        $data = $request->validate(['weekly_ordinary_hours' => ['nullable', 'integer', 'min:1', 'max:80']]);
        $access->team()->forceFill(['weekly_ordinary_hours' => $data['weekly_ordinary_hours'] ?? null])->save();

        return back()->with('status', ($data['weekly_ordinary_hours'] ?? null)
            ? 'Time above '.$data['weekly_ordinary_hours'].' hours a week is now flagged as overtime.'
            : 'Overtime is no longer flagged.');
    }

    private function supervisor(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->canManageWorkforce(), 403);

        return $access;
    }
}
