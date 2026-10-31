<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something everyone in a position must hold (R-32 Phase 6a) — a licence, a medical, an
 * induction. Matched against certifications by name, case-insensitively.
 *
 * @property int $id
 * @property int $team_id
 * @property int $position_id
 * @property string $name
 */
class PositionRequirement extends Model
{
    use HasTeamScope;

    protected $fillable = ['name'];

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }
}
