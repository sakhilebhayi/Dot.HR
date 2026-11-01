<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step for one person: done, or a course asked for or booked (linked to its training record).
 *
 * @property int $id
 * @property int $team_id
 * @property int $learning_path_enrolment_id
 * @property int $learning_path_step_id
 * @property int|null $training_record_id
 * @property Carbon|null $done_on
 * @property int|null $confirmed_by
 * @property-read TrainingRecord|null $training
 * @property-read LearningPathStep $step
 */
class LearningPathProgress extends Model
{
    use HasTeamScope;

    protected $table = 'learning_path_progress';

    protected function casts(): array
    {
        return ['done_on' => 'date'];
    }

    /** @return BelongsTo<LearningPathStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(LearningPathStep::class, 'learning_path_step_id');
    }

    /** @return BelongsTo<TrainingRecord, $this> */
    public function training(): BelongsTo
    {
        return $this->belongsTo(TrainingRecord::class, 'training_record_id');
    }
}
