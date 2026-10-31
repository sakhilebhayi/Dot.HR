<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Models\Position;
use App\Models\PositionRequirement;
use App\Services\Hr\Audit;
use App\Services\Hr\Readiness;
use App\Support\Hr\HrAccess;
use App\Support\Hr\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Certifications and readiness (R-32 Phase 6a): what each position requires, who holds what,
 * who is missing, expired or about to lapse. HR administrators and supervisors read it;
 * HR administrators record and verify.
 */
class CertificationController extends Controller
{
    public function index(Request $request, Readiness $readiness): View
    {
        $access = HrAccess::current($request->user());
        abort_unless($access && ($access->canManageWorkforce()), 403);

        return view('certifications.index', [
            'gaps' => $readiness->gaps($access->team()->id),
            'expiring' => EmployeeCertification::query()->whereNotNull('expires_on')
                ->whereDate('expires_on', '<=', LocalTime::today()->addDays(EmployeeCertification::SOON_DAYS))
                ->with('employee')->orderBy('expires_on')->get(),
            'positions' => Position::query()->orderBy('title')->get(),
            'requirements' => PositionRequirement::query()->get()->groupBy('position_id'),
            'employees' => Employee::query()->where('status', '!=', 'terminated')->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'all' => EmployeeCertification::query()->with('employee', 'verifier')->orderBy('name')->get(),
            'isAdmin' => $access->isAdmin(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $access = $this->admin($request);

        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:140'],
            'issuer' => ['nullable', 'string', 'max:140'],
            'issued_on' => ['nullable', 'date', 'before_or_equal:today'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'verified' => ['nullable', 'boolean'],
        ]);

        $employee = Employee::query()->findOrFail($data['employee_id']);

        $cert = new EmployeeCertification($data);
        $cert->forceFill([
            'team_id' => $access->team()->id,
            'employee_id' => $employee->id,
            'verified_by' => ($data['verified'] ?? false) ? $request->user()->id : null,
            'verified_at' => ($data['verified'] ?? false) ? now() : null,
        ])->save();

        Audit::record('certification.added', $cert, ['name', 'expires_on'], [], $cert->team_id);

        return back()->with('status', $data['name'].' recorded for '.$employee->fullName().'.');
    }

    public function verify(Request $request, EmployeeCertification $certification): RedirectResponse
    {
        $this->admin($request);
        $certification->forceFill(['verified_by' => $request->user()->id, 'verified_at' => now()])->save();
        Audit::record('certification.verified', $certification, [], [], $certification->team_id);

        return back()->with('status', 'Marked as verified — you have seen the original.');
    }

    public function destroy(Request $request, EmployeeCertification $certification): RedirectResponse
    {
        $this->admin($request);
        Audit::record('certification.removed', $certification, ['name'], [], $certification->team_id);
        $certification->delete();

        return back()->with('status', 'Removed.');
    }

    public function addRequirement(Request $request, Position $position): RedirectResponse
    {
        $access = $this->admin($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:140']]);

        $name = trim($data['name']);
        $requirement = PositionRequirement::query()->where('position_id', $position->id)->where('name', $name)->first()
            ?? new PositionRequirement;
        $requirement->forceFill(['team_id' => $access->team()->id, 'position_id' => $position->id, 'name' => $name])->save();

        return back()->with('status', $position->title.' now requires '.$name.'.');
    }

    public function removeRequirement(Request $request, PositionRequirement $requirement): RedirectResponse
    {
        $this->admin($request);
        $requirement->delete();

        return back()->with('status', 'Requirement removed.');
    }

    private function admin(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->isAdmin(), 403);

        return $access;
    }
}
