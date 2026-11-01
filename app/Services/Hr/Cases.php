<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\HrCase;
use App\Models\HrCaseEntry;
use App\Models\HrGrant;
use App\Models\Team;
use App\Models\User;
use App\Notifications\LifecycleNotification;
use App\Support\Hr\HrAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Employee relations cases (R-32 brief §15). Every step of the process lives here so the
 * rules hold whichever screen calls them: the timeline is append-only, a step that the person
 * should know about writes a shared entry in plain words, and every change is audited by field
 * name and status only — never the text of what was said.
 *
 * Notices carry what South Africa's Code of Good Practice: Dismissal (LRA Schedule 8, item 4)
 * says a person facing an allegation is entitled to; a dismissal outcome carries the 30-day
 * window to refer a dispute (LRA s191). These are prompts for a fair process, not legal advice.
 *
 * Notifications name the case reference only. Their titles, which may also go out by email
 * through Dot.Notify, never name a person or what the case is about.
 */
final class Cases
{
    /** Open a case. A grievance lodged by the employee arrives with source 'employee'. */
    public function open(Team $team, array $data, User $by, string $source = 'hr'): HrCase
    {
        $case = DB::transaction(function () use ($team, $data, $by, $source) {
            $case = new HrCase([
                'type' => $data['type'],
                'category' => $data['category'],
                'title' => trim($data['title']),
                'summary' => trim($data['summary']),
                'occurred_on' => $data['occurred_on'] ?? null,
            ]);
            $case->forceFill([
                'team_id' => $team->id,
                'employee_id' => $data['employee_id'] ?? null,
                'respondent_employee_id' => $data['respondent_employee_id'] ?? null,
                'restricted' => (bool) ($data['restricted'] ?? false),
                'handler_user_id' => $data['handler_user_id'] ?? null,
                'source' => $source,
                'opened_by' => $by->id,
                'status' => 'open',
            ])->save();

            Audit::record('case.opened', $case, ['type', 'category', 'restricted'], [], $team->id);

            return $case;
        });

        if ($source === 'employee') {
            $this->tellHandlers($case, 'A new grievance was lodged', $case->reference().' was lodged and is waiting for HR.');
        } elseif ($case->handler_user_id && $case->handler_user_id !== $by->id) {
            $this->tellHandlers($case, 'A case was assigned to you', $case->reference().' is now yours to handle.');
        }

        return $case;
    }

    /** Write on the timeline. A shared entry is shown to the person, and they are told. */
    public function addEntry(HrCase $case, string $kind, string $body, bool $shared, User $by, bool $byEmployee = false): HrCaseEntry
    {
        $entry = new HrCaseEntry(['kind' => $kind, 'body' => trim($body), 'shared' => $shared || $byEmployee]);
        $entry->forceFill([
            'team_id' => $case->team_id,
            'hr_case_id' => $case->id,
            'author_user_id' => $by->id,
            'by_employee' => $byEmployee,
            'created_at' => now(),
        ])->save();

        Audit::record('case.entry_added', $case, ['entries'], ['kind' => $kind, 'shared' => $entry->shared], $case->team_id);

        if ($byEmployee) {
            $this->tellHandlers($case, 'A reply on an HR case', $case->reference().' has a new reply from the person it concerns.');
        } elseif ($entry->shared) {
            $this->tellSubject($case, $kind === 'notice' ? 'You have a notice from HR' : 'An update on an HR matter', $case->reference().' has something new for you to read.');
        }

        return $entry;
    }

    public function startInvestigation(HrCase $case, User $by): void
    {
        $this->stageOrFail($case, ['open']);
        $this->move($case, 'investigating');
        $this->addEntry($case, 'update', 'Investigation started.', false, $by);
    }

    /**
     * Set a disciplinary hearing or a grievance meeting and send the person a notice. Returns a
     * caution when the date leaves little time to prepare (the Code asks for reasonable time).
     */
    public function setHearing(HrCase $case, Carbon $at, string $place, ?string $chair, string $points, User $by): ?string
    {
        $this->stageOrFail($case, ['open', 'investigating', 'hearing']);
        if ($case->type === 'incident') {
            throw ValidationException::withMessages(['hearing_at' => 'An incident record has no hearing.']);
        }
        if ($at->isPast()) {
            throw ValidationException::withMessages(['hearing_at' => 'Choose a date and time that has not passed.']);
        }

        $case->forceFill(['hearing_at' => $at, 'hearing_place' => trim($place), 'hearing_chair' => $chair !== null && trim($chair) !== '' ? trim($chair) : null])->save();
        $this->move($case, 'hearing', ['hearing_at']);

        $when = $at->format('l j F Y \a\t H:i');
        $who = $case->hearing_chair ? ' It will be chaired by '.$case->hearing_chair.'.' : '';

        $body = $case->type === 'disciplinary'
            ? "You are asked to attend a disciplinary hearing on {$when}, at {$case->hearing_place}.{$who}\n\n"
                ."What is alleged:\n".trim($points)."\n\n"
                .'At the hearing you may state your side and answer the allegations. You may bring a fellow employee '
                .'or a shop steward (trade union representative) to help you. If you need more time to prepare, tell HR now.'
            : "You are invited to a meeting about your grievance on {$when}, at {$case->hearing_place}.{$who}\n\n"
                ."What we will discuss:\n".trim($points)."\n\n"
                .'You may bring a fellow employee or a shop steward with you. If the time does not suit you, tell HR.';

        $this->addEntry($case, 'notice', $body, true, $by);

        return $at->lt(now()->addHours(48))
            ? 'The hearing is less than two days away. Make sure they have reasonable time to prepare (Schedule 8, item 4).'
            : null;
    }

    /** Record the outcome. Warnings get an expiry date; a dismissal carries the referral window. */
    public function decide(HrCase $case, string $outcome, string $reasons, bool $share, ?int $warningMonths, User $by): void
    {
        $this->stageOrFail($case, ['open', 'investigating', 'hearing']);
        if (! array_key_exists($outcome, HrCase::OUTCOMES[$case->type] ?? [])) {
            throw ValidationException::withMessages(['outcome' => 'Choose an outcome for this kind of case.']);
        }

        $expires = null;
        if (in_array($outcome, HrCase::WARNINGS, true)) {
            $months = $warningMonths ?? (int) config('hr.warning_months.'.$outcome, 6);
            $expires = now()->addMonthsNoOverflow(max(1, min(24, $months)))->toDateString();
        }

        $case->forceFill([
            'outcome' => $outcome,
            'outcome_reasons' => trim($reasons),
            'decided_at' => now(),
            'decided_by' => $by->id,
            'warning_expires_on' => $expires,
        ])->save();
        $this->move($case, 'decided', ['outcome', 'outcome_reasons', 'warning_expires_on'], ['outcome' => $outcome]);

        $body = 'Outcome: '.$case->outcomeLabel().".\n\n".trim($reasons);
        if ($expires) {
            $body .= "\n\nThis warning stays on your record until ".Carbon::parse($expires)->format('j F Y').', and then no longer counts.';
        }
        if ($outcome === 'dismissal') {
            $body .= "\n\nIf you believe the dismissal was unfair, you may refer a dispute to the CCMA, or to your bargaining "
                .'council if there is one, within 30 days of the date of dismissal.';
        }
        if ($case->type !== 'incident') {
            $body .= "\n\nIf you want to appeal, tell HR in writing.";
        }

        $this->addEntry($case, 'outcome', $body, $share, $by);
    }

    /** The person (or HR on their behalf) appeals a decided case. */
    public function appeal(HrCase $case, string $grounds, User $by, bool $byEmployee): void
    {
        if ($case->status !== 'decided' || $case->type === 'incident') {
            throw ValidationException::withMessages(['grounds' => 'Only a decided grievance or disciplinary case can be appealed.']);
        }

        $case->forceFill(['appealed_at' => now()])->save();
        $this->move($case, 'appealed', ['appealed_at']);
        $this->addEntry($case, 'appeal', 'Appeal lodged. Grounds:'."\n".trim($grounds), true, $by, $byEmployee);
    }

    public function decideAppeal(HrCase $case, string $result, string $reasons, User $by): void
    {
        if ($case->status !== 'appealed' || ! array_key_exists($result, HrCase::APPEAL_OUTCOMES)) {
            throw ValidationException::withMessages(['appeal_outcome' => 'Choose how the appeal was decided.']);
        }

        $wasWarning = $case->isWarning();
        $case->forceFill(['appeal_outcome' => $result, 'appeal_decided_at' => now()])->save();
        $this->move($case, 'decided', ['appeal_outcome'], ['appeal_outcome' => $result]);

        $body = HrCase::APPEAL_OUTCOMES[$result].".\n\n".trim($reasons);
        if ($result === 'upheld' && $wasWarning) {
            $body .= "\n\nThe warning no longer counts.";
        }
        $this->addEntry($case, 'outcome', $body, true, $by);
    }

    public function close(HrCase $case, User $by): void
    {
        $this->openOrFail($case);
        $case->forceFill(['closed_at' => now()])->save();
        $this->move($case, 'closed', ['closed_at']);
        $this->addEntry($case, 'update', 'Case closed.', $this->subjectCanSee($case), $by);
    }

    public function withdraw(HrCase $case, string $reason, User $by): void
    {
        $this->openOrFail($case);
        $case->forceFill(['closed_at' => now()])->save();
        $this->move($case, 'withdrawn', ['closed_at']);
        $this->addEntry($case, 'update', 'Withdrawn. '.trim($reason), $this->subjectCanSee($case), $by);
    }

    /** Assign a handler (an HR administrator or an employee relations holder) and set who may see it. */
    public function assign(HrCase $case, ?User $handler, bool $restricted, User $by): void
    {
        if ($handler !== null) {
            $access = HrAccess::forTeamId($handler, $case->team_id);
            if (! $access?->canHandleCases()) {
                throw ValidationException::withMessages(['handler_user_id' => 'Choose an HR administrator or someone with employee relations access.']);
            }
        }

        $before = $case->handler_user_id;
        $case->forceFill(['handler_user_id' => $handler?->id, 'restricted' => $restricted])->save();
        Audit::record('case.assigned', $case, ['handler_user_id', 'restricted'], ['restricted' => $restricted], $case->team_id);

        if ($handler !== null && $handler->id !== $before && $handler->id !== $by->id) {
            $handler->notify(new LifecycleNotification('A case was assigned to you', $case->reference().' is now yours to handle.', route('cases.show', $case), teamId: $case->team_id));
        }
    }

    /** A work injury reported to the Compensation Fund (or another body) on this date. */
    public function markReported(HrCase $case, Carbon $on, User $by): void
    {
        $case->forceFill(['reported_externally_on' => $on->toDateString()])->save();
        Audit::record('case.reported_externally', $case, ['reported_externally_on'], [], $case->team_id);
        $this->addEntry($case, 'update', 'Reported outside the business on '.$on->format('j F Y').'.', false, $by);
    }

    /** The person confirms they received a shared notice. */
    public function confirmReceipt(HrCaseEntry $entry): void
    {
        if ($entry->received_at === null) {
            $entry->forceFill(['received_at' => now()])->save();
            Audit::record('case.notice_received', $entry->case, [], ['entry_id' => $entry->id], $entry->team_id);
        }
    }

    /**
     * The members who may handle cases here: the owner, the administrators, and members granted
     * employee relations (HrAccess::canHandleCases, answered for the whole team in two queries).
     *
     * @return Collection<int, User>
     */
    public function handlersFor(Team $team): Collection
    {
        $members = DB::table('team_user')->where('team_id', $team->id);
        $ids = (clone $members)->where('role', 'admin')->pluck('user_id')
            ->merge(HrGrant::query()->where('team_id', $team->id)->where('capability', HrGrant::EMPLOYEE_RELATIONS)
                ->whereIn('user_id', (clone $members)->pluck('user_id'))->pluck('user_id'))
            ->push($team->user_id)->unique();

        return User::query()->whereIn('id', $ids->all())->orderBy('name')->get();
    }

    /** @param list<string> $fields */
    private function move(HrCase $case, string $status, array $fields = [], array $extra = []): void
    {
        $from = $case->getOriginal('status');
        $case->forceFill(['status' => $status])->save();
        Audit::record('case.'.$status, $case, ['status', ...$fields], ['status' => ['from' => $from, 'to' => $status], ...$extra], $case->team_id);
    }

    private function openOrFail(HrCase $case): void
    {
        if (! $case->isOpen()) {
            throw ValidationException::withMessages(['case' => 'This case is '.strtolower($case->statusLabel()).'.']);
        }
    }

    /** @param list<string> $stages */
    private function stageOrFail(HrCase $case, array $stages): void
    {
        if (! in_array($case->status, $stages, true)) {
            throw ValidationException::withMessages(['case' => 'That step does not fit a case that is '.strtolower($case->statusLabel()).'.']);
        }
    }

    private function subjectCanSee(HrCase $case): bool
    {
        return $case->type === 'grievance' || $case->entries()->where('shared', true)->exists();
    }

    /** The handler, or with none: the owner for a restricted case, the HR administrators otherwise. */
    private function tellHandlers(HrCase $case, string $title, string $message): void
    {
        $team = $case->team()->firstOrFail();
        $people = $case->handler_user_id
            ? User::query()->whereKey($case->handler_user_id)->get()
            : ($case->restricted ? User::query()->whereKey($team->user_id)->get() : $team->administrators());

        Notification::send($people, new LifecycleNotification($title, $message, route('cases.show', $case), teamId: $team->id));
    }

    private function tellSubject(HrCase $case, string $title, string $message): void
    {
        $employee = $case->employee_id ? Employee::withoutTeamScope(fn () => Employee::withTrashed()->find($case->employee_id)) : null;
        if ($employee?->user_id && ($person = User::query()->find($employee->user_id))) {
            $person->notify(new LifecycleNotification($title, $message, route('my-work.cases.show', $case), teamId: $case->team_id));
        }
    }
}
