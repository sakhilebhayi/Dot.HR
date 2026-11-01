<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cell of the competency matrix: this position needs this competency at this level.
 *
 * @property int $id
 * @property int $team_id
 * @property int $position_id
 * @property int $competency_id
 * @property int $expected_level
 * @property-read Competency $competency
 */
class PositionCompetency extends Model
{
    use HasTeamScope;

    protected function casts(): array
    {
        return ['expected_level' => 'integer'];
    }

    /** @return BelongsTo<Competency, $this> */
    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }
}
