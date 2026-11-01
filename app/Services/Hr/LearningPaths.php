<?php

namespace App\Services\Hr;

use App\Models\Competency;
use App\Models\Employee;
use App\Models\LearningPath;
use App\Models\LearningPathEnrolment;
use App\Models\LearningPathProgress;
use App\Models\LearningPathStep;
use App\Models\Team;
use App\Models\TrainingRecord;
use App\Models\User;
use App\Notifications\LifecycleNotification;
use App\Support\Hr\LocalTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Learning paths (brief §10): ordered steps a person works through, with progress shown to the
 * person and HR as steps done. Nothing here scores, averages or ranks people. A course step is
 * booked as training and is done when that training is completed, so the training record (and
 * any certificate it gives) stays the one source; other steps are marked done by the person or
 * by HR, and who confirmed each one is kept.
 */
final class LearningPaths
{
    /** @param array<string, mixed> $data */
    public function save(Team $team, array $data, User $by, ?LearningPath $path = null): LearningPath
    {
        $path ??= new LearningPath;
        $path->fill([
            'name' => trim((string) $data['name']),
            'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
            'position_id' => $data['position_id'] ?? null,
            'self_enrol' => (bool) ($data['self_enrol'] ?? false),
        ]);
        $path->forceFill(['team_id' => $team->id, 'created_by' => $path->created_by ?? $by->id])->save();
        Audit::record($path->wasRecentlyCreated ? 'learning_path.created' : 'learning_path.updated', $path, ['name', 'position_id', 'self_enrol'], [], $team->id);

        return $path;
    }

    /** Retire a path: nobody new joins it; people on it keep their record of what they did. */
    public function retire(LearningPath $path): void
    {
        $path->forceFill(['retired_at' => now()])->save();
        Audit::record('learning_path.retired', $path, ['retired_at'], [], $path->team_id);
    }

    /** @param array<string, mixed> $data */
    public function saveStep(LearningPath $path, array $data, ?LearningPathStep $step = null): LearningPathStep
    {
        if (filled($data['competency_id'] ?? null) && ! Competency::query()->live()->whereKey($data['competency_id'])->exists()) {
            throw ValidationException::withMessages(['competency_id' => 'That competency is not in use here.']);
        }
        $isCourse = ($data['kind'] ?? null) === 'course';

        $step ??= new LearningPathStep(['position' => (int) LearningPathStep::query()->where('learning_path_id', $path->id)->whereNull('retired_at')->max('position') + 1]);
        if ($step->exists && $step->kind !== $data['kind'] && LearningPathProgress::query()->where('learning_path_step_id', $step->id)->exists()) {
            throw ValidationException::withMessages(['kind' => 'People have started this step, so its kind stays. Retire it and add a new one instead.']);
        }
        $step->fill([
            'title' => trim((string) $data['title']),
            'kind' => $data['kind'],
            'detail' => filled($data['detail'] ?? null) ? trim((string) $data['detail']) : null,
            'provider' => $isCourse && filled($data['provider'] ?? null) ? trim((string) $data['provider']) : null,
            'certification_name' => $isCourse && filled($data['certification_name'] ?? null) ? trim((string) $data['certification_name']) : null,
            'competency_id' => filled($data['competency_id'] ?? null) ? (int) $data['competency_id'] : null,
            'within_days' => filled($data['within_days'] ?? null) ? (int) $data['within_days'] : null,
        ]);
        $step->forceFill(['team_id' => $path->team_id, 'learning_path_id' => $path->id])->save();
        Audit::record($step->wasRecentlyCreated ? 'learning_path.step_added' : 'learning_path.step_updated', $step, ['title', 'kind', 'within_days'], [], $path->team_id);

        return $step;
    }

    public function moveStep(LearningPathStep $step, string $direction): void
    {
        $siblings = LearningPathStep::query()->where('learning_path_id', $step->learning_path_id)->whereNull('retired_at')->orderBy('position')->orderBy('id')->get()->values();
        $i = $siblings->search(fn (LearningPathStep $s) => $s->id === $step->id);
        $j = $direction === 'up' ? $i - 1 : $i + 1;
        if ($i === false || ! isset($siblings[$j])) {
            return;
        }

        DB::transaction(function () use ($siblings, $i, $j) {
            $order = $siblings->all();
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
            foreach ($order as $position => $s) {
                $s->forceFill(['position' => $position])->save();
            }
        });
    }

    /** Retire a step: it leaves the path for everyone; what people already did stays on record. */
    public function retireStep(LearningPathStep $step): void
    {
        $step->forceFill(['retired_at' => now()])->save();
        Audit::record('learning_path.step_retired', $step, ['retired_at'], [], $step->team_id);

        // Someone whose only open step was this one has now finished the path.
        LearningPathEnrolment::query()->where('learning_path_id', $step->learning_path_id)->whereNull('completed_at')->whereNull('dropped_at')
            ->with('path')->get()->each(fn (LearningPathEnrolment $e) => $this->settle($e));
    }

    /** Put a person on a path. Someone who dropped it starts again; someone who finished it stays finished. */
    public function enrol(LearningPath $path, Employee $employee, User $by): LearningPathEnrolment
    {
        if ($path->retired_at !== null || $path->team_id !== $employee->team_id) {
            throw ValidationException::withMessages(['path' => 'That path is not in use here.']);
        }
        $enrolment = LearningPathEnrolment::query()->where('learning_path_id', $path->id)->where('employee_id', $employee->id)->first();
        if ($enrolment?->completed_at) {
            throw ValidationException::withMessages(['path' => $employee->first_name.' has already completed '.$path->name.'.']);
        }
        if ($enrolment !== null && $enrolment->dropped_at === null) {
            return $enrolment;
        }

        $enrolment ??= new LearningPathEnrolment;
        $enrolment->forceFill([
            'team_id' => $path->team_id, 'learning_path_id' => $path->id, 'employee_id' => $employee->id,
            'assigned_by' => $by->id, 'started_on' => LocalTime::today()->toDateString(), 'dropped_at' => null,
        ])->save();
        Audit::record('learning_path.enrolled', $enrolment, ['learning_path_id', 'started_on'], [], $path->team_id);

        if ($employee->user_id && $employee->user_id !== $by->id && ($person = User::query()->find($employee->user_id))) {
            $count = LearningPathStep::query()->where('learning_path_id', $path->id)->whereNull('retired_at')->count();
            $person->notify(new LifecycleNotification('A learning path for you', $path->name.', '.$count.' '.($count === 1 ? 'step' : 'steps').'.', route('my-work.learning.show', $enrolment), teamId: $path->team_id));
        }

        return $enrolment;
    }

    /**
     * Everyone currently in the path's position who is not on it yet (and has not finished it).
     *
     * @return int how many were put on it
     */
    public function enrolPosition(LearningPath $path, User $by): int
    {
        abort_if($path->position_id === null, 422, 'This path is not tied to a position.');
        $taken = LearningPathEnrolment::query()->where('learning_path_id', $path->id)
            ->where(fn ($q) => $q->whereNotNull('completed_at')->orWhereNull('dropped_at'))->pluck('employee_id');

        $people = Employee::query()->where('position_id', $path->position_id)->where('status', '!=', 'terminated')->whereNotIn('id', $taken)->get();
        $people->each(fn (Employee $e) => $this->enrol($path, $e, $by));

        return $people->count();
    }

    public function drop(LearningPathEnrolment $enrolment): void
    {
        abort_unless($enrolment->isActive(), 422, 'Only a path in progress can be stopped.');
        $enrolment->forceFill(['dropped_at' => now()])->save();
        Audit::record('learning_path.dropped', $enrolment, ['dropped_at'], [], $enrolment->team_id);
    }

    /** Mark a session, reading or practice step done. Courses are done through their training record. */
    public function markDone(LearningPathEnrolment $enrolment, LearningPathStep $step, User $by, ?Carbon $on = null): LearningPathProgress
    {
        $this->checkStep($enrolment, $step);
        if ($step->isCourse()) {
            throw ValidationException::withMessages(['step' => 'A course is done when its training is recorded as completed.']);
        }

        $row = $this->row($enrolment, $step);
        $row->forceFill(['done_on' => ($on ?? LocalTime::today())->toDateString(), 'confirmed_by' => $by->id])->save();
        Audit::record('learning_path.step_done', $row, ['done_on'], ['step_id' => $step->id], $enrolment->team_id);
        $this->settle($enrolment);

        return $row;
    }

    /** Undo a step marked done by mistake. The person may undo only their own marks. */
    public function undo(LearningPathEnrolment $enrolment, LearningPathStep $step, User $by, bool $asHr): void
    {
        $this->checkStep($enrolment, $step, allowFinished: true);
        $row = LearningPathProgress::query()->where('learning_path_enrolment_id', $enrolment->id)->where('learning_path_step_id', $step->id)->first();
        if ($row === null || $row->done_on === null || $step->isCourse()) {
            throw ValidationException::withMessages(['step' => 'Nothing to undo on this step.']);
        }
        if (! $asHr && $row->confirmed_by !== $by->id) {
            throw ValidationException::withMessages(['step' => 'HR marked this step done, so ask HR to change it.']);
        }

        $row->forceFill(['done_on' => null, 'confirmed_by' => null])->save();
        Audit::record('learning_path.step_undone', $row, ['done_on'], ['step_id' => $step->id], $enrolment->team_id);
        if ($enrolment->completed_at !== null) {
            $enrolment->forceFill(['completed_at' => null])->save();
        }
    }

    /** The person asks for a course step: a training request HR decides in Training, as any other. */
    public function askForCourse(LearningPathEnrolment $enrolment, LearningPathStep $step, User $by): TrainingRecord
    {
        return $this->course($enrolment, $step, 'requested', $by);
    }

    /** HR books a course step for the person. */
    public function bookCourse(LearningPathEnrolment $enrolment, LearningPathStep $step, User $by, ?string $startsOn): TrainingRecord
    {
        $record = $this->course($enrolment, $step, 'planned', $by, $startsOn);
        $employee = $enrolment->employee;
        if ($employee->user_id && $employee->user_id !== $by->id && ($person = User::query()->find($employee->user_id))) {
            $person->notify(new LifecycleNotification('Training booked for you', $record->title.($record->starts_on ? ' on '.$record->starts_on->format('j M Y') : '').'.', teamId: $employee->team_id));
        }

        return $record;
    }

    /** Called when training is completed: the course step it was booked for is done. */
    public function trainingCompleted(TrainingRecord $record, User $by): void
    {
        LearningPathProgress::query()->where('training_record_id', $record->id)->whereNull('done_on')->get()
            ->each(function (LearningPathProgress $row) use ($record, $by) {
                $row->forceFill(['done_on' => $record->completed_on?->toDateString() ?? LocalTime::today()->toDateString(), 'confirmed_by' => $by->id])->save();
                Audit::record('learning_path.step_done', $row, ['done_on'], ['training_record_id' => $record->id], $row->team_id);
                if ($enrolment = LearningPathEnrolment::query()->with('path')->find($row->learning_path_enrolment_id)) {
                    $this->settle($enrolment);
                }
            });
    }

    /** Called when a training request is declined: the step is free to be asked for or booked again. */
    public function trainingDeclined(TrainingRecord $record): void
    {
        LearningPathProgress::query()->where('training_record_id', $record->id)->whereNull('done_on')->update(['training_record_id' => null]);
    }

    /**
     * The person's path, step by step, with where each one stands.
     *
     * @return list<array{step: LearningPathStep, progress: LearningPathProgress|null, state: string, due: Carbon|null}>
     */
    public function rows(LearningPathEnrolment $enrolment): array
    {
        $steps = LearningPathStep::query()->where('learning_path_id', $enrolment->learning_path_id)->whereNull('retired_at')
            ->with('competency')->orderBy('position')->orderBy('id')->get();
        $progress = LearningPathProgress::query()->where('learning_path_enrolment_id', $enrolment->id)->with('training')->get()->keyBy('learning_path_step_id');
        $today = LocalTime::today();

        return $steps->map(function (LearningPathStep $step) use ($progress, $enrolment, $today) {
            $p = $progress->get($step->id);
            $due = $step->within_days !== null ? $enrolment->started_on->copy()->addDays($step->within_days) : null;
            $state = match (true) {
                $p?->done_on !== null => 'done',
                $p?->training?->status === 'planned' => 'booked',
                $p?->training?->status === 'requested' => 'asked',
                $due !== null && $due->lt($today) && $enrolment->isActive() => 'late',
                default => 'open',
            };

            return ['step' => $step, 'progress' => $p, 'state' => $state, 'due' => $due];
        })->values()->all();
    }

    /**
     * Steps done out of live steps, for many enrolments at once.
     *
     * @param  Collection<int, LearningPathEnrolment>  $enrolments
     * @return array<int, array{done: int, total: int}> keyed by enrolment id
     */
    public function counts(Collection $enrolments): array
    {
        if ($enrolments->isEmpty()) {
            return [];
        }
        $totals = LearningPathStep::query()->whereIn('learning_path_id', $enrolments->pluck('learning_path_id')->unique())->whereNull('retired_at')
            ->selectRaw('learning_path_id, count(*) as n')->groupBy('learning_path_id')->pluck('n', 'learning_path_id');
        $done = LearningPathProgress::query()->whereIn('learning_path_enrolment_id', $enrolments->pluck('id'))->whereNotNull('done_on')
            ->whereHas('step', fn ($q) => $q->whereNull('retired_at'))
            ->selectRaw('learning_path_enrolment_id, count(*) as n')->groupBy('learning_path_enrolment_id')->pluck('n', 'learning_path_enrolment_id');

        return $enrolments->mapWithKeys(fn (LearningPathEnrolment $e) => [$e->id => ['done' => (int) $done->get($e->id, 0), 'total' => (int) $totals->get($e->learning_path_id, 0)]])->all();
    }

    private function course(LearningPathEnrolment $enrolment, LearningPathStep $step, string $status, User $by, ?string $startsOn = null): TrainingRecord
    {
        $this->checkStep($enrolment, $step);
        if (! $step->isCourse()) {
            throw ValidationException::withMessages(['step' => 'Only a course is booked as training.']);
        }
        $row = $this->row($enrolment, $step);
        if ($row->done_on !== null || in_array($row->training?->status, ['requested', 'planned'], true)) {
            throw ValidationException::withMessages(['step' => 'This course is already asked for, booked or done.']);
        }

        return DB::transaction(function () use ($enrolment, $step, $status, $by, $startsOn, $row) {
            $record = new TrainingRecord;
            $record->forceFill([
                'team_id' => $enrolment->team_id, 'employee_id' => $enrolment->employee_id, 'title' => $step->title,
                'provider' => $step->provider, 'kind' => 'external', 'status' => $status,
                'certification_name' => $step->certification_name, 'currency' => 'ZAR', 'paid_by' => 'business',
                'reason' => $status === 'requested' ? 'Step on the learning path '.$enrolment->path->name : null,
                'requested_by' => $status === 'requested' ? $by->id : null,
                ...($status === 'planned' ? ['decided_by' => $by->id, 'decided_at' => now(), 'starts_on' => $startsOn] : []),
            ])->save();
            Audit::record($status === 'planned' ? 'training.planned' : 'training.requested', $record, ['title', 'kind'], ['learning_path_step_id' => $step->id], $enrolment->team_id);
            $row->forceFill(['training_record_id' => $record->id])->save();

            return $record;
        });
    }

    /** Finish the path when every live step is done; tell whoever put the person on it. */
    private function settle(LearningPathEnrolment $enrolment): void
    {
        if (! $enrolment->isActive()) {
            return;
        }
        $count = $this->counts(collect([$enrolment]))[$enrolment->id];
        if ($count['total'] === 0 || $count['done'] < $count['total']) {
            return;
        }

        $enrolment->forceFill(['completed_at' => now()])->save();
        Audit::record('learning_path.completed', $enrolment, ['completed_at'], [], $enrolment->team_id);
        $employee = $enrolment->employee()->first();
        if ($enrolment->assigned_by && $enrolment->assigned_by !== $employee?->user_id && ($by = User::query()->find($enrolment->assigned_by))) {
            $by->notify(new LifecycleNotification('A learning path was completed', $employee?->first_name.' completed '.$enrolment->path->name.'.', route('learning.paths.show', $enrolment->learning_path_id), teamId: $enrolment->team_id));
        }
    }

    private function row(LearningPathEnrolment $enrolment, LearningPathStep $step): LearningPathProgress
    {
        $row = LearningPathProgress::query()->where('learning_path_enrolment_id', $enrolment->id)->where('learning_path_step_id', $step->id)->with('training')->first() ?? new LearningPathProgress;
        $row->forceFill(['team_id' => $enrolment->team_id, 'learning_path_enrolment_id' => $enrolment->id, 'learning_path_step_id' => $step->id]);

        return $row;
    }

    private function checkStep(LearningPathEnrolment $enrolment, LearningPathStep $step, bool $allowFinished = false): void
    {
        if ($step->learning_path_id !== $enrolment->learning_path_id || $step->retired_at !== null) {
            throw ValidationException::withMessages(['step' => 'That step is not on this path.']);
        }
        if ($enrolment->dropped_at !== null || (! $allowFinished && $enrolment->completed_at !== null)) {
            throw ValidationException::withMessages(['step' => 'This path is no longer in progress.']);
        }
    }
}
