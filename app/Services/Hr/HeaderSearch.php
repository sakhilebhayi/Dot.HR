<?php

namespace App\Services\Hr;

use App\Models\Application;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\User;
use App\Models\Vacancy;
use App\Support\Hr\EmailIndex;
use App\Support\Hr\HrAccess;
use App\Support\Hr\LocalTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * The search in the top bar (R-52). One place decides what each kind of person may find, so the box can never show more
 * than the page it links to would:
 *
 *   administrator  people (by name, or an exact work email), every leave request (name, leave type, dates and status),
 *                  positions, candidates.
 *   manager        people (name, position), the NAMES of people who are away now or soon (never the leave type or reason),
 *                  positions. No candidates unless a hiring grant says so.
 *   employee       only their own record and their own leave.
 *   candidate      (the personal workspace) only their own applications and the public job board.
 *
 * Everything is read through the team-scoped models (HasTeamScope: the person's current team, fail closed), and each
 * query also names the team explicitly, so a business can never see another business's rows. Never searched or shown:
 * salary or pay (Dot.HR holds none for people), leave reasons, phone, email addresses (except an admin's exact
 * lookup, which confirms nothing it does not already show), case or review content, notes, feedback.
 */
class HeaderSearch
{
    /** Rows per group. */
    public const PER_GROUP = 5;

    /** Characters below which nothing is asked. */
    public const MIN_CHARS = 2;

    /**
     * @return array<string, list<array{url: string, label: string, sub: string}>> groups in order, empty groups left out
     */
    public function search(?User $user, string $query, bool $personal = false): array
    {
        $term = self::clean($query);

        if ($user === null || mb_strlen($term) < self::MIN_CHARS) {
            return [];
        }

        $groups = $personal ? $this->candidate($user, $term) : $this->business($user, $term);

        return array_filter($groups, fn (array $rows) => $rows !== []);
    }

    /** Wildcards and control characters out: the term is only ever bound as a LIKE parameter. */
    public static function clean(string $query): string
    {
        $term = preg_replace('/[\x00-\x1F\x7F%_\\\\]+/u', ' ', $query) ?? '';

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $term) ?? ''), 0, 60);
    }

    /** @return array<string, list<array{url: string, label: string, sub: string}>> */
    private function business(User $user, string $term): array
    {
        $access = HrAccess::current($user);

        if ($access === null) {
            return [];
        }

        $team = $access->team()->id;
        $like = '%'.mb_strtolower($term).'%';

        $groups = [
            'People' => $this->people($access, $team, $term, $like),
            'Leave' => $this->leave($access, $team, $like),
        ];

        if ($access->isAdmin() || $access->isManager()) {
            $groups['Positions'] = Position::query()->where('team_id', $team)
                ->where(fn (Builder $w) => $w->whereRaw('LOWER(title) LIKE ?', [$like])->orWhereRaw('LOWER(department) LIKE ?', [$like]))
                ->orderBy('title')->limit(self::PER_GROUP)->get(['id', 'title', 'department'])
                ->map(fn (Position $p) => ['url' => $access->isAdmin() ? route('positions.edit', $p) : route('positions.index'), 'label' => $p->title, 'sub' => $p->department ?: 'Position'])
                ->all();
        }

        if ($access->canReviewCandidates()) {
            $groups['Candidates'] = $this->candidates($team, $like);
        }

        return $groups;
    }

    /** @return list<array{url: string, label: string, sub: string}> */
    private function people(HrAccess $access, int $team, string $term, string $like): array
    {
        if (! $access->canListEmployees()) {
            return [];
        }

        $query = Employee::query()->where('team_id', $team)->with('position:id,title');

        if (! $access->isAdmin() && ! $access->isManager()) {
            // Someone linked to a record sees that record only.
            $mine = $access->employee();

            if ($mine === null) {
                return [];
            }
            $query->whereKey($mine->id);
        }

        $query->where(function (Builder $w) use ($like, $access, $term) {
            $w->whereRaw('LOWER(first_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                ->orWhereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$like]);

            // The email is encrypted: only an administrator's exact lookup, through the blind index, as the employee list does.
            if ($access->isAdmin() && ($hash = EmailIndex::hash($term))) {
                $w->orWhere('email_hash', $hash);
            }
        });

        return $query->orderBy('last_name')->orderBy('first_name')->limit(self::PER_GROUP)->get()
            ->map(fn (Employee $e) => [
                'url' => route('employees.show', $e),
                'label' => $e->fullName(),
                'sub' => $e->position?->title ?: 'Employee',
            ])->all();
    }

    /** @return list<array{url: string, label: string, sub: string}> */
    private function leave(HrAccess $access, int $team, string $like): array
    {
        if (! $access->canListEmployees()) {
            return [];
        }

        $today = LocalTime::today()->toDateString();

        $query = LeaveRequest::query()->where('team_id', $team)
            ->with(['employee:id,first_name,last_name,deleted_at', 'leaveType:id,name'])
            ->whereHas('employee', fn (Builder $e) => $e->withoutTrashed());

        if ($access->isAdmin()) {
            $query->where(fn (Builder $w) => $w
                ->whereHas('employee', fn (Builder $e) => $e->where(fn (Builder $n) => $n
                    ->whereRaw('LOWER(first_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                    ->orWhereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$like])))
                ->orWhereHas('leaveType', fn (Builder $t) => $t->whereRaw('LOWER(name) LIKE ?', [$like])));
        } elseif ($access->isManager()) {
            // Names only, and only for people who are away: approved leave that has not ended.
            $query->where('status', LeaveRequest::STATUS_APPROVED)->whereDate('end_date', '>=', $today)
                ->whereHas('employee', fn (Builder $e) => $e->where(fn (Builder $n) => $n
                    ->whereRaw('LOWER(first_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                    ->orWhereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$like])));
        } else {
            $mine = $access->employee();

            if ($mine === null) {
                return [];
            }
            $query->where('employee_id', $mine->id)
                ->whereHas('leaveType', fn (Builder $t) => $t->whereRaw('LOWER(name) LIKE ?', [$like]));
        }

        return $query->latest('start_date')->limit(self::PER_GROUP)->get()
            ->filter(fn (LeaveRequest $r) => $r->employee !== null)
            ->map(function (LeaveRequest $r) use ($access) {
                $dates = $r->start_date->isSameDay($r->end_date)
                    ? $r->start_date->format('j M Y')
                    : $r->start_date->format('j M').' to '.$r->end_date->format('j M Y');

                if ($access->isAdmin()) {
                    return ['url' => route('leave-requests.show', $r), 'label' => $r->employee->fullName().', '.$r->typeLabel().', '.$dates, 'sub' => ucfirst($r->status)];
                }

                // A manager sees who is away and until when: nothing about the kind of leave or why.
                if ($access->isManager()) {
                    return ['url' => route('leave-requests.show', $r), 'label' => $r->employee->fullName(), 'sub' => 'Away to '.$r->end_date->format('j M')];
                }

                return ['url' => route('leave-requests.show', $r), 'label' => $r->typeLabel().', '.$dates, 'sub' => ucfirst($r->status)];
            })->values()->all();
    }

    /** @return list<array{url: string, label: string, sub: string}> */
    private function candidates(int $team, string $like): array
    {
        return Application::query()->where('applications.team_id', $team)
            ->with(['user:id,name', 'vacancy:id,title'])
            ->where(fn (Builder $w) => $w
                ->whereHas('user', fn (Builder $u) => $u->whereRaw('LOWER(name) LIKE ?', [$like]))
                ->orWhereHas('vacancy', fn (Builder $v) => $v->whereRaw('LOWER(title) LIKE ?', [$like])))
            ->latest()->limit(self::PER_GROUP)->get()
            ->filter(fn (Application $a) => $a->user !== null && $a->vacancy !== null)
            ->map(fn (Application $a) => [
                'url' => route('hiring.applications.show', $a),
                'label' => $a->user->name.', '.$a->vacancy->title,
                'sub' => $a->stageLabel(),
            ])->values()->all();
    }

    /** @return array<string, list<array{url: string, label: string, sub: string}>> */
    private function candidate(User $user, string $term): array
    {
        $like = '%'.mb_strtolower($term).'%';

        $mine = Application::withoutTeamScope(fn () => Application::query()
            ->where('user_id', $user->id)
            ->with(['vacancy:id,team_id,title', 'vacancy.team:id,name'])
            ->whereHas('vacancy', fn (Builder $v) => $v->whereRaw('LOWER(title) LIKE ?', [$like]))
            ->latest()->limit(self::PER_GROUP)->get()
            ->filter(fn (Application $a) => $a->vacancy !== null)
            ->map(fn (Application $a) => [
                'url' => route('me.applications.show', $a->id),
                'label' => $a->vacancy->title.($a->vacancy->team ? ', '.$a->vacancy->team->name : ''),
                'sub' => $a->candidateStageLabel(),
            ])->values()->all());

        return ['My applications' => $mine, 'Open roles' => self::openRoles($like)];
    }

    /**
     * The public job board's roles (published and open only, the same scope the board uses), found by title or place.
     *
     * @return list<array{url: string, label: string, sub: string}>
     */
    public static function openRoles(string $like): array
    {
        return Vacancy::withoutTeamScope(fn () => Vacancy::query()->openToPublic()->with('team:id,name')
            ->where(fn (Builder $w) => $w->whereRaw('LOWER(title) LIKE ?', [$like])->orWhereRaw('LOWER(location) LIKE ?', [$like]))
            ->latest('published_at')->limit(self::PER_GROUP)->get()
            ->map(fn (Vacancy $v) => [
                'url' => route('jobs.show', $v->id),
                'label' => $v->title,
                'sub' => $v->team?->name ?? 'Open role',
            ])->all());
    }
}
