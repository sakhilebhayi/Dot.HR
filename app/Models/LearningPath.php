<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An ordered set of steps a person works through (brief §10). Optionally for one position, so
 * HR can put everyone in that position on it; optionally open, so people may start it themselves.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string|null $description
 * @property int|null $position_id
 * @property bool $self_enrol
 * @property int|null $created_by
 * @property Carbon|null $retired_at
 * @property-read Position|null $position
 */
class LearningPath extends Model
{
    use HasTeamScope;

    protected $fillable = ['name', 'description', 'position_id', 'self_enrol'];

    protected function casts(): array
    {
        return ['self_enrol' => 'boolean', 'retired_at' => 'datetime'];
    }

    /** @param Builder<LearningPath> $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('retired_at');
    }

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** Live steps in order. @return HasMany<LearningPathStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(LearningPathStep::class)->whereNull('retired_at')->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<LearningPathEnrolment, $this> */
    public function enrolments(): HasMany
    {
        return $this->hasMany(LearningPathEnrolment::class);
    }
}
