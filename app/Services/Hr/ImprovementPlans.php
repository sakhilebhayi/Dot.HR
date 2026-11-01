<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\HrCase;
use App\Models\ImprovementPlan;
use App\Models\ImprovementPlanCheckIn;
use App\Models\ImprovementPlanObjective;
use App\Models\Team;
use App\Models\TrainingRecord;
use App\Models\User;
use App\Notifications\LifecycleNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Performance improvement plans (brief §10 and §15). The steps follow LRA Schedule 8, items 8
 * and 9, for poor work performance: say what standard is not met, give guidance, training or
 * counselling, allow a reasonable time, check in along the way, then decide. A plan that ends
 * with the standard still not met is not itself a sanction: any further step goes through an
 * incapacity enquiry, which is an HR case.
 *
 * Notifications name the plan reference only; their titles never say what the plan is about.
 */
final class ImprovementPlans
{
    /**
     * Draft a plan. Nothing reaches the person until it is shared.
     *
     * @param  list<array{title: string, measure: string, due_on?: string|null}>  $objectives
     */
    public function draft(Team $team, Employee $employee, array $data, array $objectives, ?User $manager, User $by, ?HrCase $case = null, ?string $training = null): ImprovementPlan
    {
        $this->checkManager($team, $employee, $manager);

        $plan = DB::transaction(function () use ($team, $employee, $data, $objectives, $manager, $by, $case, $training) {
            $plan = new ImprovementPlan($data);
            $plan->forceFill([
                'team_id' => $team->id,
                'employee_id' => $employee->id,
                'manager_user_id' => $manager?->id,
                'hr_case_id' => $case?->id,
                'created_by' => $by->id,
                'status' => 'draft',
            ])->save();
            $this->replaceObjectives($plan, $objectives);

            if ($training !== null && trim($training) !== '') {
                $record = new TrainingRecord;
                $record->forceFill([
                    'team_id' => $team->id, 'employee_id' => $employee->id, 'title' => trim($training), 'kind' => 'internal',
                    'status' => 'planned', 'reason' => 'Support for improvement plan '.$plan->reference(),
                    'decided_by' => $by->id, 'decided_at' => now(),
                ])->save();
                Audit::record('training.planned', $record, ['title', 'kind'], [], $team->id);
            }

            Audit::record('plan.drafted', $plan, ['concern', 'standard', 'support', 'starts_on', 'ends_on', 'manager_user_id'], [], $team->id);

            return $plan;
        });

        if ($manager && $manager->id !== $by->id) {
            $manager->notify(new LifecycleNotification('An improvement plan needs your support', $plan->reference().' names you as the supporting manager.', route('plans.show', $plan), teamId: $team->id));
        }

        return $plan;
    }

    /** @param list<array{title: string, measure: string, due_on?: string|null}> $objectives */
    public function revise(ImprovementPlan $plan, array $data, array $objectives, ?User $manager, ?HrCase $case = null): void
    {
        $this->expect($plan, ['draft']);
        $team = $plan->employee->team()->firstOrFail();
        $this->checkManager($team, $plan->employee, $manager);

        DB::transaction(function () use ($plan, $data, $objectives, $manager, $case) {
            $plan->fill($data)->forceFill(['manager_user_id' => $manager?->id, 'hr_case_id' => $case?->id])->save();
            $this->replaceObjectives($plan, $objectives);
            Audit::record('plan.revised', $plan, Audit::changedFields($plan) ?: ['objectives'], [], $plan->team_id);
        });
    }

    /** Share the plan: the person is told and it starts. Returns a caution when the period is short. */
    public function share(ImprovementPlan $plan, User $by): ?string
    {
        $this->expect($plan, ['draft']);
        if ($plan->objectives()->doesntExist()) {
            throw ValidationException::withMessages(['plan' => 'Add at least one objective, with how it will be measured, before sharing the plan.']);
        }

        $plan->forceFill(['status' => 'active', 'shared_at' => now()])->save();
        Audit::record('plan.shared', $plan, ['status'], ['status' => ['from' => 'draft', 'to' => 'active']], $plan->team_id);
        $this->tellPerson($plan, 'An improvement plan was shared with you', $plan->reference().' is ready for you to read.');

        return $plan->periodDays() < ImprovementPlan::SHORT_PERIOD_DAYS
            ? 'The plan runs '.$plan->periodDays().' days. Check that this is a reasonable time to improve for this work (Schedule 8, item 9).'
            : null;
    }

    /** The person confirms they read it, optionally with their response. Reading is not agreeing. */
    public function acknowledge(ImprovementPlan $plan, ?string $response): void
    {
        $this->expect($plan, ['active']);
        $plan->forceFill([
            'acknowledged_at' => $plan->acknowledged_at ?? now(),
            'employee_response' => $response !== null && trim($response) !== '' ? trim($response) : $plan->employee_response,
        ])->save();
        Audit::record('plan.acknowledged', $plan, $response ? ['employee_response'] : [], [], $plan->team_id);
        $this->tellManagers($plan, 'An improvement plan was read', $plan->reference().' was read by the person it is for.');
    }

    /**
     * Record a check-in and where each objective stands. Always shared with the person.
     *
     * @param  array<int, string>  $statuses  objective id => open | met | not_met
     */
    public function checkIn(ImprovementPlan $plan, Carbon $heldOn, string $notes, array $statuses, User $by): ImprovementPlanCheckIn
    {
        $this->expect($plan, ['active']);
        if ($heldOn->lt($plan->starts_on) || $heldOn->isFuture()) {
            throw ValidationException::withMessages(['held_on' => 'A check-in falls between the start of the plan and today.']);
        }

        $checkIn = DB::transaction(function () use ($plan, $heldOn, $notes, $statuses, $by) {
            $checkIn = new ImprovementPlanCheckIn(['held_on' => $heldOn->toDateString(), 'notes' => trim($notes)]);
            $checkIn->forceFill(['team_id' => $plan->team_id, 'improvement_plan_id' => $plan->id, 'author_user_id' => $by->id])->save();

            foreach ($plan->objectives as $objective) {
                $status = $statuses[$objective->id] ?? null;
                if ($status !== null && array_key_exists($status, ImprovementPlanObjective::STATUSES) && $status !== $objective->status) {
                    $objective->forceFill(['status' => $status])->save();
                }
            }

            Audit::record('plan.checked_in', $plan, ['check_ins'], ['held_on' => $heldOn->toDateString()], $plan->team_id);

            return $checkIn;
        });

        $this->tellPerson($plan, 'A check-in on your improvement plan', $plan->reference().' has notes from your check-in.');

        return $checkIn;
    }

    /** The person adds their own comment to a check-in. */
    public function comment(ImprovementPlanCheckIn $checkIn, string $comment): void
    {
        $checkIn->forceFill(['employee_comment' => trim($comment), 'commented_at' => now()])->save();
        $plan = $checkIn->plan;
        Audit::record('plan.commented', $plan, ['employee_comment'], [], $plan->team_id);
        $this->tellManagers($plan, 'A comment on an improvement plan', $plan->reference().' has a comment from the person it is for.');
    }

    /** Give more time. The extension and its reason go on the record the person sees. */
    public function extend(ImprovementPlan $plan, Carbon $until, string $reason, User $by): void
    {
        $this->expect($plan, ['active']);
        if ($until->lte($plan->ends_on)) {
            throw ValidationException::withMessages(['ends_on' => 'Choose a date after the current end, '.$plan->ends_on->format('j M Y').'.']);
        }

        $from = $plan->ends_on->toDateString();
        $plan->forceFill(['ends_on' => $until->toDateString(), 'extensions' => $plan->extensions + 1])->save();
        Audit::record('plan.extended', $plan, ['ends_on'], ['ends_on' => ['from' => $from, 'to' => $until->toDateString()]], $plan->team_id);

        $note = new ImprovementPlanCheckIn(['held_on' => now()->toDateString(), 'notes' => 'The plan was extended to '.$until->format('j F Y').'. '.trim($reason)]);
        $note->forceFill(['team_id' => $plan->team_id, 'improvement_plan_id' => $plan->id, 'author_user_id' => $by->id])->save();

        $this->tellPerson($plan, 'Your improvement plan was extended', $plan->reference().' now runs to '.$until->format('j M Y').'.');
    }

    public function complete(ImprovementPlan $plan, string $outcome, string $notes, User $by): void
    {
        $this->expect($plan, ['active']);
        if (! array_key_exists($outcome, ImprovementPlan::OUTCOMES)) {
            throw ValidationException::withMessages(['outcome' => 'Choose how the plan ended.']);
        }

        $plan->forceFill(['status' => 'completed', 'outcome' => $outcome, 'outcome_notes' => trim($notes), 'decided_at' => now()])->save();
        Audit::record('plan.completed', $plan, ['status', 'outcome', 'outcome_notes'], ['outcome' => $outcome], $plan->team_id);
        $this->tellPerson($plan, 'Your improvement plan has ended', $plan->reference().' has its outcome.');
    }

    public function cancel(ImprovementPlan $plan, string $reason, User $by): void
    {
        $this->expect($plan, ['draft', 'active']);
        $wasShared = $plan->shared_at !== null;
        $plan->forceFill(['status' => 'cancelled', 'outcome_notes' => trim($reason), 'decided_at' => now()])->save();
        Audit::record('plan.cancelled', $plan, ['status'], [], $plan->team_id);

        if ($wasShared) {
            $this->tellPerson($plan, 'Your improvement plan was stopped', $plan->reference().' was cancelled.');
        }
    }

    /** Members who may support a plan for this person: anyone in the business except the person. @return Collection<int, User> */
    public function managersFor(Team $team, Employee $employee): Collection
    {
        $ids = DB::table('team_user')->where('team_id', $team->id)->pluck('user_id')->push($team->user_id)->unique();

        return User::query()->whereIn('id', $ids->all())->when($employee->user_id, fn ($q) => $q->whereKeyNot($employee->user_id))->orderBy('name')->get();
    }

    /** @param list<array{title: string, measure: string, due_on?: string|null}> $objectives */
    private function replaceObjectives(ImprovementPlan $plan, array $objectives): void
    {
        $plan->objectives()->delete();
        foreach ($objectives as $i => $row) {
            $objective = new ImprovementPlanObjective(['title' => trim($row['title']), 'measure' => trim($row['measure']), 'due_on' => $row['due_on'] ?? null, 'position' => $i]);
            $objective->forceFill(['team_id' => $plan->team_id, 'improvement_plan_id' => $plan->id])->save();
        }
        $plan->unsetRelation('objectives');
    }

    private function checkManager(Team $team, Employee $employee, ?User $manager): void
    {
        if ($manager === null) {
            return;
        }
        if (($employee->user_id !== null && $manager->id === $employee->user_id) || ! $manager->belongsToTeam($team)) {
            throw ValidationException::withMessages(['manager_user_id' => 'Choose someone in this business, other than the person, to support the plan.']);
        }
    }

    /** @param list<string> $statuses */
    private function expect(ImprovementPlan $plan, array $statuses): void
    {
        if (! in_array($plan->status, $statuses, true)) {
            throw ValidationException::withMessages(['plan' => 'That step does not fit a plan that is '.strtolower($plan->statusLabel()).'.']);
        }
    }

    private function tellPerson(ImprovementPlan $plan, string $title, string $message): void
    {
        $userId = $plan->employee->user_id;
        if ($userId && ($person = User::query()->find($userId))) {
            $person->notify(new LifecycleNotification($title, $message, route('my-work.plans.show', $plan), teamId: $plan->team_id));
        }
    }

    private function tellManagers(ImprovementPlan $plan, string $title, string $message): void
    {
        $team = Team::query()->findOrFail($plan->team_id);
        $people = $plan->manager_user_id
            ? User::query()->whereKey($plan->manager_user_id)->get()
            : $team->administrators();

        Notification::send($people, new LifecycleNotification($title, $message, route('plans.show', $plan), teamId: $team->id));
    }
}
