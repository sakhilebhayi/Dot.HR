<?php

namespace App\Support\Hr;

use App\Models\Employee;
use App\Models\HrAuditLog;
use App\Models\ImprovementPlan;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Jetstream\Jetstream;

/**
 * The top bar's navigation and account menu, as the plain arrays the shared dot-ui header takes (R-52). One list prints the
 * desktop bar and the phone drawer, so nothing is reachable in one and missing from the other.
 *
 * The daily places stay in the bar (at most five); everything else sits in four named groups. Each entry appears only for the
 * people who may open it (the same checks the routes make), so a group a person has nothing in is not shown at all.
 * The personal (career) workspace has four flat items.
 */
final class WorkspaceNav
{
    /** The career workspace: personal pages and the public job board. */
    public static function isPersonal(Request $request): bool
    {
        return $request->routeIs('me.*', 'jobs.*', 'people.*');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function nav(Request $request, ?User $user): array
    {
        $is = fn (string ...$patterns): bool => $request->routeIs(...$patterns);
        $link = fn (string $label, string $route, string $pattern): array => ['label' => $label, 'href' => route($route), 'current' => $is($pattern)];

        if ($user === null) {
            return [$link('Job board', 'jobs.index', 'jobs.*')];
        }

        if (self::isPersonal($request)) {
            return [
                $link('Home', 'me.home', 'me.home'),
                $link('Find work', 'jobs.index', 'jobs.*'),
                $link('Applications', 'me.applications.index', 'me.applications.*'),
                $link('Profile', 'me.profile', 'me.profile'),
            ];
        }

        $access = HrAccess::current($user);
        $nav = [$link('Home', 'dashboard', 'dashboard')];

        if ($access?->employee()) {
            $nav[] = $link('My work', 'my-work.index', 'my-work.*');
        }
        if ($access?->canReviewCandidates()) {
            $nav[] = $link('Hiring', 'hiring.index', 'hiring.*');
        }
        if ($user->can('viewAny', Employee::class)) {
            $nav[] = $link($user->can('create', Employee::class) ? 'Employees' : 'People', 'employees.index', 'employees.*');
        }
        if ($user->can('viewAny', LeaveRequest::class)) {
            $nav[] = $link('Leave', 'leave-requests.index', 'leave-requests.*');
        }

        $workforce = [];
        if ($access && ($access->canManageWorkforce() || $access->isManager())) {
            $workforce[] = $link('Schedule', 'schedule.index', 'schedule.*');
        }
        if ($access?->canManageWorkforce()) {
            $workforce[] = $link('Attendance', 'attendance.index', 'attendance.*');
            $workforce[] = $link('Timesheets', 'timesheets.index', 'timesheets.*');
        }
        if ($access?->isAdmin()) {
            $workforce[] = $link('Lifecycle', 'lifecycle.index', 'lifecycle.*');
            $workforce[] = $link('Requests', 'requests.index', 'requests.*');
            $workforce[] = $link('Leaving', 'leaving.index', 'leaving.*');
            $workforce[] = $link('Contractors', 'contractors.index', 'contractors.*');
        }

        $growth = [];
        if ($access?->canManageWorkforce()) {
            $growth[] = $link('Training', 'training.index', 'training.*');
            $growth[] = $link('Learning paths', 'learning.index', 'learning.*');
            $growth[] = $link('Certifications', 'certifications.index', 'certifications.*');
        }
        if ($access && ($access->isAdmin() || Review::query()->where('reviewer_id', $user->id)->exists())) {
            $growth[] = $link('Reviews', 'reviews.index', 'reviews.*');
        }
        if ($access && ($access->isAdmin() || $access->employee())) {
            $growth[] = $link('Feedback', 'feedback.index', 'feedback.*');
        }
        if ($access && ($access->isAdmin() || ImprovementPlan::query()->where('manager_user_id', $user->id)->exists())) {
            $growth[] = $link('Improvement plans', 'plans.index', 'plans.*');
        }
        if ($access?->isAdmin()) {
            $growth[] = $link('Competencies', 'competencies.index', 'competencies.*');
        }

        $records = [];
        if ($access && ($access->isAdmin() || $access->employee())) {
            $records[] = $link('Policies', 'policies.index', 'policies.*');
        }
        if ($access?->canHandleCases()) {
            $records[] = $link('Cases', 'cases.index', 'cases.*');
        }
        if ($access?->isAdmin()) {
            $records[] = $link('Insights', 'insights.index', 'insights.*');
            $records[] = $link('Payroll inputs', 'payroll.index', 'payroll.*');
        }

        $setup = [];
        if ($user->can('viewAny', Position::class)) {
            $setup[] = $link('Positions', 'positions.index', 'positions.*');
        }
        if ($user->can('viewAny', LeaveType::class)) {
            $setup[] = $link('Leave types', 'leave-types.index', 'leave-types.*');
        }
        if ($access?->canManageAccess()) {
            $setup[] = $link('Access', 'access.index', 'access.*');
        }
        if ($user->can('viewAny', HrAuditLog::class)) {
            $setup[] = $link('Audit log', 'audit-log.index', 'audit-log.*');
        }

        foreach (['Workforce' => $workforce, 'Growth' => $growth, 'Records' => $records, 'Setup' => $setup] as $label => $items) {
            if ($items !== []) {
                $nav[] = ['label' => $label, 'items' => $items];
            }
        }

        return $nav;
    }

    /**
     * The account menu: who is signed in, the workspace switch (personal and each business, a PUT form for another business),
     * the person's own settings and team pages. Sign out and the theme are the header's own.
     *
     * @return array<string, mixed>
     */
    public static function account(Request $request, User $user): array
    {
        $personal = self::isPersonal($request);
        $team = $user->currentTeam;
        $is = fn (string ...$patterns): bool => $request->routeIs(...$patterns);

        $links = [
            ['label' => 'Profile and security', 'href' => route('profile.show'), 'current' => $is('profile.*')],
        ];

        if (! $personal && Jetstream::hasTeamFeatures() && $team) {
            $links[] = ['label' => 'Team settings', 'href' => route('teams.show', $team->id), 'current' => $is('teams.show')];
        }
        if (Jetstream::hasTeamFeatures() && $user->can('create', Jetstream::newTeamModel())) {
            $links[] = ['label' => 'Create a team', 'href' => route('teams.create'), 'current' => $is('teams.create')];
        }

        $links[] = ['heading' => 'Workspace'];
        $links[] = ['label' => 'Personal career space', 'href' => route('me.home'), 'current' => $personal];

        foreach ($user->allTeams() as $business) {
            $current = $team?->id === $business->id;
            $row = ['label' => $business->name, 'current' => ! $personal && $current];

            // Already the current team: entering it needs no switch, just its home.
            $links[] = $current
                ? $row + ['href' => route('dashboard')]
                : $row + ['form' => ['action' => route('current-team.update'), 'method' => 'PUT', 'fields' => ['team_id' => $business->id]]];
        }

        return [
            'name' => $user->name,
            'email' => $user->email,
            'team' => $personal ? 'Personal' : ($team?->name ?? 'Personal'),
            'links' => $links,
            'signOut' => ['action' => route('logout')],
            'theme' => true,
        ];
    }
}
