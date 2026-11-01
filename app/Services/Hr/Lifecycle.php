<?php

namespace App\Services\Hr;

use App\Models\ChecklistTemplate;
use App\Models\Employee;
use App\Models\LifecycleChecklist;
use App\Models\LifecycleTask;
use App\Models\User;
use App\Notifications\LifecycleNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Starting, working and finishing onboarding and offboarding (R-32 Phase 5).
 *
 * One open checklist per employee per process: starting again while one is open returns the
 * open one (the employee row is locked while checking), so a double click or the hire flow
 * running twice never builds a second checklist. Every start, completion and cancellation
 * is audited; tasks record who completed them and when.
 */
final class Lifecycle
{
    /**
     * @param  array{due_on?: string|null, last_day?: string|null, reason?: string|null}  $options
     */
    public function start(Employee $employee, string $process, ?int $actorId, array $options = []): LifecycleChecklist
    {
        if (! array_key_exists($process, LifecycleChecklist::PROCESSES)) {
            throw new RuntimeException('Unknown process.');
        }

        return LifecycleChecklist::withoutTeamScope(fn () => DB::transaction(function () use ($employee, $process, $actorId, $options) {
            Employee::query()->withTrashed()->whereKey($employee->id)->lockForUpdate()->first();

            $open = LifecycleChecklist::query()
                ->where('employee_id', $employee->id)
                ->where('process', $process)
                ->where('status', 'open')
                ->first();

            if ($open) {
                return $open;
            }

            $checklist = new LifecycleChecklist;
            $checklist->forceFill([
                'team_id' => $employee->team_id,
                'employee_id' => $employee->id,
                'process' => $process,
                'status' => 'open',
                'due_on' => $options['due_on'] ?? ($process === 'onboarding' ? $employee->start_date : null),
                'last_day' => $process === 'offboarding' ? ($options['last_day'] ?? null) : null,
                'reason' => $process === 'offboarding' ? ($options['reason'] ?? null) : null,
                'started_by' => $actorId,
            ])->save();

            foreach (ChecklistTemplate::itemsFor($employee->team_id, $process) as $i => $item) {
                $task = new LifecycleTask;
                $task->forceFill([
                    'team_id' => $employee->team_id,
                    'lifecycle_checklist_id' => $checklist->id,
                    'title' => mb_substr((string) $item['title'], 0, 160),
                    'owner' => $item['owner'] === 'employee' ? 'employee' : 'hr',
                    'kind' => $item['kind'] === 'acknowledge' ? 'acknowledge' : 'task',
                    'body' => $item['body'] ?? null,
                    'position' => $i,
                ])->save();
            }

            Audit::record($process.'.started', $checklist, [], array_filter(['reason' => $checklist->reason]), $employee->team_id);

            $person = $employee->user_id ? User::query()->find($employee->user_id) : null;
            if ($person && $checklist->tasks()->where('owner', 'employee')->exists()) {
                $person->notify(new LifecycleNotification(
                    $process === 'onboarding' ? 'Welcome — a few things to do' : 'Your last days: a few things to do',
                    'Open Dot.HR to see what '.$employee->team->name.' needs from you.',
                    teamId: $employee->team_id,
                ));
            }

            return $checklist;
        }));
    }

    /** Mark a task done (or not done). The caller has already decided who may. */
    public function setDone(LifecycleTask $task, bool $done, int $actorId): void
    {
        abort_unless($task->checklist->status === 'open', 422, 'This checklist is closed.');

        $task->forceFill([
            'completed_at' => $done ? ($task->completed_at ?? now()) : null,
            'completed_by' => $done ? ($task->completed_by ?? $actorId) : null,
        ])->save();

        if ($done && $task->kind === 'acknowledge') {
            Audit::record('policy.acknowledged', $task, [], ['task_id' => $task->id], $task->team_id);
        }
    }

    /**
     * Close a checklist. Finishing an offboarding sets the employee's end date to the agreed
     * last day and their status to terminated — the one place a departure changes the record.
     * Tasks still open stay recorded as not done; nothing is ticked on anyone's behalf.
     */
    public function finish(LifecycleChecklist $checklist, int $actorId): void
    {
        abort_unless($checklist->status === 'open', 422);

        DB::transaction(function () use ($checklist) {
            $checklist->forceFill(['status' => 'done', 'completed_at' => now()])->save();

            if ($checklist->process === 'offboarding') {
                $employee = $checklist->employee;
                $employee->forceFill([
                    'status' => 'terminated',
                    'end_date' => $checklist->last_day ?? Carbon::today(),
                ])->save();
            }

            Audit::record($checklist->process.'.finished', $checklist, [], [
                'open_tasks' => $checklist->tasks()->whereNull('completed_at')->count(),
            ], $checklist->team_id);
        });
    }

    public function cancel(LifecycleChecklist $checklist): void
    {
        abort_unless($checklist->status === 'open', 422);

        $checklist->forceFill(['status' => 'cancelled', 'completed_at' => now()])->save();
        Audit::record($checklist->process.'.cancelled', $checklist, [], [], $checklist->team_id);
    }
}
