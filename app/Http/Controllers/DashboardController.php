<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Offer;
use App\Models\Vacancy;
use App\Services\Hr\LeaveBalance;
use App\Support\Hr\HrAccess;
use Illuminate\Http\Request;

/**
 * One dashboard, three views of it, by role (HrAccess): admins get workforce and approval
 * numbers, managers the headcount and who is away, and a linked employee their own
 * balances and leave. A member with no linked record is told how to get one.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, LeaveBalance $balances)
    {
        $team = $request->user()->currentTeam;

        if (! $team) {
            return redirect()->route('teams.create');
        }

        $access = HrAccess::current($request->user());
        $data = ['role' => 'none'];

        if ($access?->isAdmin() || $access?->isManager()) {
            $data = [
                'role' => $access->isAdmin() ? 'admin' : 'manager',
                // "Employed" is anyone not terminated; "on leave" is computed from approved leave today.
                'headcount' => Employee::where('status', '!=', 'terminated')->count(),
                'onLeaveCount' => Employee::onLeaveOn()->count(),
                'openPositions' => $team->positions()->count(),
                // Names only: a manager may know who is away, never why.
                'awayToday' => Employee::onLeaveOn()->orderBy('last_name')->orderBy('first_name')->limit(10)->get(['id', 'first_name', 'last_name']),
            ];

            if ($access->isAdmin()) {
                $data += [
                    'pendingLeaveRequests' => LeaveRequest::where('status', 'pending')->count(),
                    'recentLeaveRequests' => LeaveRequest::where('status', 'pending')
                        ->whereHas('employee', fn ($q) => $q->withoutTrashed())
                        ->with(['employee', 'leaveType'])
                        ->latest()
                        ->take(5)
                        ->get(),
                ];
            }
        }

        // Hiring at a glance for anyone who works the pipeline (R-32 Phase 4).
        if ($access?->canReviewCandidates()) {
            $data['hiring'] = [
                'openRoles' => Vacancy::query()->where('status', 'published')->count(),
                'newApplications' => Application::query()->where('stage', 'new')->count(),
                'upcomingInterviews' => Interview::query()->where('status', 'scheduled')->where('starts_at', '>=', now())->where('starts_at', '<=', now()->addDays(7))->count(),
                'offersOut' => Offer::query()->where('status', 'sent')->count(),
            ];
        }

        if ($mine = $access?->employee()) {
            $data['role'] = $data['role'] === 'none' ? 'self' : $data['role'];
            $data['mine'] = $mine;
            $data['myBalances'] = LeaveType::orderBy('name')->get()
                ->filter->isTracked()
                ->map(fn (LeaveType $type) => ['type' => $type, 'snapshot' => $balances->for($mine, $type, now())]);
            $data['myLeave'] = LeaveRequest::where('employee_id', $mine->id)
                ->whereIn('status', ['pending', 'approved'])
                ->whereDate('end_date', '>=', now()->toDateString())
                ->with('leaveType')
                ->orderBy('start_date')
                ->take(5)
                ->get();
        }

        return view('dashboard', $data);
    }
}
