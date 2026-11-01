<?php

namespace App\Support\Hr;

use App\Models\Employee;
use App\Models\HrCase;
use App\Models\HrGrant;
use App\Models\ImprovementPlan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single definition of who may see and do what in Dot.HR. Every policy, controller
 * and view asks this class (directly or through a policy); nothing else interprets team
 * roles.
 *
 *   admin    the team owner or a Jetstream `admin`: the HR administrator. Sees everyone,
 *            decides leave, manages employees, positions, leave types, reads the audit log.
 *   manager  a Jetstream `editor`: manager-lite. Sees the employee list basics (name,
 *            position, type, status) and leave dates and status. Never sees contact
 *            details, leave reasons or leave types of others, and changes nothing.
 *   self     any member whose account is linked to an employee record (employees.user_id):
 *            sees and requests only their own record and leave. A manager or admin who is
 *            also linked has this on top of their role.
 *   none     a member with no role above and no linked record: sees nothing.
 *
 * A person is never trusted to be "the subject" by name or email at request time: only by
 * the linked employee record, which an admin sets or the verified-email match sets.
 */
final class HrAccess
{
    private function __construct(private User $user, private Team $team) {}

    /** Access in the user's current team, or null when they have none or are not in it. */
    public static function current(User $user): ?self
    {
        $team = $user->currentTeam;

        return $team && $user->belongsToTeam($team) ? new self($user, $team) : null;
    }

    /** Access in the team with this id, or null when the user does not belong to it. */
    public static function forTeamId(User $user, int $teamId): ?self
    {
        $team = $user->allTeams()->firstWhere('id', $teamId);

        return $team ? new self($user, $team) : null;
    }

    public function team(): Team
    {
        return $this->team;
    }

    public function isAdmin(): bool
    {
        return $this->user->ownsTeam($this->team) || $this->user->hasTeamRole($this->team, 'admin');
    }

    public function isManager(): bool
    {
        return ! $this->isAdmin() && $this->user->teamRole($this->team)?->key === 'editor';
    }

    /** The employee record linked to this account in this team, if any. */
    public function employee(): ?Employee
    {
        return $this->user->hrEmployee($this->team);
    }

    public function isSubject(Employee $employee): bool
    {
        $mine = $this->employee();

        return $mine !== null && $mine->id === $employee->id;
    }

    /** May open the employee list (all rows for admin/manager, own row for self). */
    public function canListEmployees(): bool
    {
        return $this->isAdmin() || $this->isManager() || $this->employee() !== null;
    }

    /** May see this employee's email, phone and employment dates. */
    public function canSeeContact(Employee $employee): bool
    {
        return $this->isAdmin() || $this->isSubject($employee);
    }

    /**
     * Holds this business-level grant (R-32 Phase 3). A grant only exists for a member —
     * this object is never built for a non-member (current()/forTeamId()), so a grant
     * left behind after InfoDot removes someone from the company opens nothing.
     */
    public function hasGrant(string $capability): bool
    {
        return HrGrant::query()
            ->where('team_id', $this->team->id)
            ->where('user_id', $this->user->id)
            ->where('capability', $capability)
            ->exists();
    }

    /** May publish roles and work the hiring pipeline. Membership alone never confers it. */
    public function canRecruit(): bool
    {
        return $this->isAdmin() || $this->hasGrant(HrGrant::RECRUITER);
    }

    /** May review candidates and give feedback (Phase 4 limits it to their own roles). */
    public function canReviewCandidates(): bool
    {
        return $this->canRecruit() || $this->hasGrant(HrGrant::HIRING_MANAGER);
    }

    /** May build schedules, record or correct attendance and decide timesheets (R-32 Phase 6a). */
    public function canManageWorkforce(): bool
    {
        return $this->isAdmin() || $this->hasGrant(HrGrant::SUPERVISOR);
    }

    /**
     * May open the employee relations desk (brief §15): HR administrators, and members granted
     * employee relations, who then see only the cases assigned to them. Managers never.
     */
    public function canHandleCases(): bool
    {
        return $this->isAdmin() || $this->hasGrant(HrGrant::EMPLOYEE_RELATIONS);
    }

    /**
     * May open this case. A restricted case is open only to its handler and the business owner
     * (a grievance about an HR administrator must not reach that administrator). Otherwise HR
     * administrators see every case and an employee relations handler sees the ones assigned to
     * them. The person the case is about uses the employee view (canSeeOwnCase), never this.
     */
    public function canSeeCase(HrCase $case): bool
    {
        if ($case->team_id !== $this->team->id) {
            return false;
        }

        $handler = $case->handler_user_id === $this->user->id && $this->canHandleCases();

        if ($case->restricted) {
            return $handler || $this->user->ownsTeam($this->team);
        }

        return $this->isAdmin() || $handler;
    }

    /**
     * The cases this person may open in this business, as a query: everything for the owner,
     * unrestricted cases plus their own for an administrator, their own for a handler, none
     * for anyone else. The same rule as canSeeCase(), for lists.
     *
     * @return Builder<HrCase>
     */
    public function visibleCases(): Builder
    {
        $query = HrCase::query()->where('team_id', $this->team->id);

        if (! $this->canHandleCases()) {
            return $query->whereRaw('1 = 0');
        }
        if ($this->user->ownsTeam($this->team)) {
            return $query;
        }

        return $this->isAdmin()
            ? $query->where(fn ($q) => $q->where('restricted', false)->orWhere('handler_user_id', $this->user->id))
            : $query->where('handler_user_id', $this->user->id);
    }

    /** The person a case is about may read its shared entries: always for their own grievance, otherwise once HR shared something. */
    public function canSeeOwnCase(HrCase $case): bool
    {
        $mine = $this->employee();

        return $mine !== null && $case->team_id === $this->team->id && $case->employee_id === $mine->id
            && ($case->type === 'grievance' || $case->entries()->where('shared', true)->exists());
    }

    /**
     * May run this improvement plan: HR administrators, and the member named as the manager
     * supporting it. A manager role alone opens nothing; only being named on the plan does.
     */
    public function canManagePlan(ImprovementPlan $plan): bool
    {
        return $plan->team_id === $this->team->id
            && ($this->isAdmin() || $plan->manager_user_id === $this->user->id)
            && ! $this->isSubjectOf($plan);
    }

    /** The person the plan is for: they see it once it is shared with them. */
    public function canSeeOwnPlan(ImprovementPlan $plan): bool
    {
        return $plan->team_id === $this->team->id && $this->isSubjectOf($plan) && $plan->shared_at !== null;
    }

    private function isSubjectOf(ImprovementPlan $plan): bool
    {
        return $this->employee()?->id === $plan->employee_id;
    }

    /** May grant and revoke recruitment capabilities in this business. */
    public function canManageAccess(): bool
    {
        return $this->isAdmin();
    }
}
