<?php

namespace App\Http\Controllers\Hiring;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\InternalInvitation;
use App\Models\User;
use App\Models\Vacancy;
use App\Notifications\LifecycleNotification;
use App\Services\Hr\Audit;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Telling a current employee about an open role they may suit (R-32 Phase 7). The person
 * decides whether to apply; the invitation changes nothing about them and goes out once.
 */
class InternalTalentController extends Controller
{
    public function invite(Request $request, Vacancy $vacancy, Employee $employee): RedirectResponse
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->canRecruit(), 403);

        if (! $vacancy->isOpen()) {
            return back()->with('error', 'Publish the role first — people can only apply to an open role.');
        }

        if ($employee->user_id === null || $employee->status === 'terminated') {
            return back()->with('error', $employee->fullName().' has no Dot.HR account to tell.');
        }

        if (InternalInvitation::query()->where('vacancy_id', $vacancy->id)->where('employee_id', $employee->id)->exists()) {
            return back()->with('error', $employee->fullName().' already knows about this role.');
        }

        $invitation = new InternalInvitation;
        $invitation->forceFill([
            'team_id' => $vacancy->team_id,
            'vacancy_id' => $vacancy->id,
            'employee_id' => $employee->id,
            'invited_by' => $request->user()->id,
        ])->save();
        Audit::record('vacancy.internal_invite', $invitation, ['vacancy_id', 'employee_id']);

        User::query()->find($employee->user_id)?->notify(new LifecycleNotification(
            'A role here you may suit',
            $vacancy->title.' is open at '.$access->team()->name.'. Have a look and apply if it interests you — it is your choice.',
            route('jobs.show', $vacancy->id),
            teamId: $vacancy->team_id,
        ));

        return back()->with('status', $employee->fullName().' has been told about the role.');
    }
}
