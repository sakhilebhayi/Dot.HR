<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Employee;
use App\Models\HrRequest;
use App\Models\Interview;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LifecycleChecklist;
use App\Models\LifecycleTask;
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

        // Joining, leaving and requests waiting on HR (R-32 Phase 5).
        if ($access?->isAdmin()) {
            $data['people'] = [
                'joining' => LifecycleChecklist::query()->where('status', 'open')->where('process', 'onboarding')->count(),
                'leaving' => LifecycleChecklist::query()->where('status', 'open')->where('process', 'offboarding')->count(),
                'openRequests' => HrRequest::query()->where('status', 'open')->count(),
            ];
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
            $data['myOpenSteps'] = LifecycleTask::query()
                ->where('owner', 'employee')
                ->whereNull('completed_at')
                ->whereHas('checklist', fn ($q) => $q->where('employee_id', $mine->id)->where('status', 'open'))
                ->count();
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

        $data['landing'] = $this->landing($access, $data);

        return view('dashboard', $data);
    }

    /**
     * The landing's one primary action for this role, the quieter actions beside it, and the
     * way back to InfoDot. The primary is the role's real first job: an HR administrator
     * decides the leave that is waiting (or adds someone when nothing is), a manager looks
     * at the team's leave, a linked employee requests leave, and a member with no record
     * here goes to their personal workspace.
     *
     * @param  array<string, mixed>  $data
     * @return array{primary: array{label: string, url: string}, secondary: list<array{label: string, url: string}>, infodot: string, platforms: string}
     */
    private function landing(?HrAccess $access, array $data): array
    {
        $secondary = [];

        if ($data['role'] === 'admin') {
            $addEmployee = [
                'label' => $data['headcount'] === 0 ? 'Add your first employee' : 'Add an employee',
                'url' => route('employees.create'),
            ];

            if ($data['pendingLeaveRequests'] > 0) {
                $primary = ['label' => 'Decide the leave waiting', 'url' => route('leave-requests.index', ['status' => 'pending'])];
                $secondary[] = $addEmployee;
            } else {
                $primary = $addEmployee;
            }
        } elseif ($data['role'] === 'manager') {
            $primary = ['label' => 'See the team\'s leave', 'url' => route('leave-requests.index')];
            $secondary[] = ['label' => 'People', 'url' => route('employees.index')];
        } elseif ($data['role'] === 'self') {
            $primary = ['label' => 'Request leave', 'url' => route('leave-requests.create')];
            $secondary[] = ['label' => 'My work', 'url' => route('my-work.index')];
        } else {
            $primary = ['label' => 'Open your personal workspace', 'url' => route('me.home')];
        }

        $infodot = rtrim((string) config('ecosystem.platforms.infodot.url'), '/');

        return [
            'primary' => $primary,
            'secondary' => $secondary,
            'infodot' => $infodot.'/home',
            'platforms' => $infodot.'/platforms',
        ];
    }
}
