<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step on a learning path. A course is booked as training and is done when that training
 * is completed; a session, reading or practice is marked done by the person or by HR.
 *
 * @property int $id
 * @property int $team_id
 * @property int $learning_path_id
 * @property int $position
 * @property string $title
 * @property string $kind
 * @property string|null $detail
 * @property string|null $provider
 * @property string|null $certification_name
 * @property int|null $competency_id
 * @property int|null $within_days
 * @property Carbon|null $retired_at
 * @property-read LearningPath $path
 * @property-read Competency|null $competency
 */
class LearningPathStep extends Model
{
    use HasTeamScope;

    public const KINDS = ['course' => 'A course', 'session' => 'A session in house', 'reading' => 'Something to read', 'practice' => 'Something to practise'];

    protected $fillable = ['title', 'kind', 'detail', 'provider', 'certification_name', 'competency_id', 'within_days'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'within_days' => 'integer', 'retired_at' => 'datetime'];
    }

    /** A course is done through its training record, never by ticking it. */
    public function isCourse(): bool
    {
        return $this->kind === 'course';
    }

    /** @return BelongsTo<LearningPath, $this> */
    public function path(): BelongsTo
    {
        return $this->belongsTo(LearningPath::class, 'learning_path_id');
    }

    /** @return BelongsTo<Competency, $this> */
    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }
}
