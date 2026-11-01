<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One person on one learning path.
 *
 * @property int $id
 * @property int $team_id
 * @property int $learning_path_id
 * @property int $employee_id
 * @property int|null $assigned_by
 * @property Carbon $started_on
 * @property Carbon|null $completed_at
 * @property Carbon|null $dropped_at
 * @property-read LearningPath $path
 * @property-read Employee $employee
 */
class LearningPathEnrolment extends Model
{
    use HasTeamScope;

    protected function casts(): array
    {
        return ['started_on' => 'date', 'completed_at' => 'datetime', 'dropped_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->completed_at === null && $this->dropped_at === null;
    }

    /** @return BelongsTo<LearningPath, $this> */
    public function path(): BelongsTo
    {
        return $this->belongsTo(LearningPath::class, 'learning_path_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<LearningPathProgress, $this> */
    public function progress(): HasMany
    {
        return $this->hasMany(LearningPathProgress::class);
    }
}
