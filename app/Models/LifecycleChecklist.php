<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One onboarding or offboarding run for one employee (R-32 Phase 5): the tasks, who owns
 * each, and whether it is still open.
 *
 * @property int $id
 * @property int $team_id
 * @property int $employee_id
 * @property string $process
 * @property string $status
 * @property Carbon|null $due_on
 * @property Carbon|null $last_day
 * @property string|null $reason
 * @property Carbon|null $completed_at
 * @property-read Employee $employee
 * @property-read Collection<int, LifecycleTask> $tasks
 */
class LifecycleChecklist extends Model
{
    use HasTeamScope;

    public const PROCESSES = ['onboarding' => 'Onboarding', 'offboarding' => 'Offboarding'];

    /** Why someone leaves, as a category only — the story stays out of an ordinary record. */
    public const REASONS = [
        'resignation' => 'Resignation',
        'contract_end' => 'End of contract',
        'retirement' => 'Retirement',
        'dismissal' => 'Dismissal',
        'retrenchment' => 'Operational requirements',
        'other' => 'Other',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'last_day' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<LifecycleTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(LifecycleTask::class)->orderBy('position');
    }

    /** @return array{done: int, total: int} */
    public function progress(): array
    {
        return ['done' => $this->tasks->whereNotNull('completed_at')->count(), 'total' => $this->tasks->count()];
    }

    public function label(): string
    {
        return self::PROCESSES[$this->process] ?? $this->process;
    }
}
