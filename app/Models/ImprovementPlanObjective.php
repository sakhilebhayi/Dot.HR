<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing a plan asks for, with how anyone can tell it is met. Marked met or not met, never scored.
 *
 * @property int $id
 * @property int $team_id
 * @property int $improvement_plan_id
 * @property string $title
 * @property string $measure
 * @property Carbon|null $due_on
 * @property string $status
 * @property int $position
 */
class ImprovementPlanObjective extends Model
{
    use HasTeamScope;

    public const STATUSES = ['open' => 'Not yet', 'met' => 'Met', 'not_met' => 'Not met'];

    protected $fillable = ['title', 'measure', 'due_on', 'position'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'position' => 'integer'];
    }

    /** @return BelongsTo<ImprovementPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ImprovementPlan::class, 'improvement_plan_id');
    }
}
