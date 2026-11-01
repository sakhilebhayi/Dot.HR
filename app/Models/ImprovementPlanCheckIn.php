<?php

namespace App\Models;

use App\Casts\SensitiveText;
use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A check-in on a plan: what was discussed and how the objectives stand, always shared with the
 * person, who may add their own comment. Internal notes belong in an HR case, not here.
 *
 * @property int $id
 * @property int $team_id
 * @property int $improvement_plan_id
 * @property Carbon $held_on
 * @property string $notes
 * @property string|null $employee_comment
 * @property Carbon|null $commented_at
 * @property int|null $author_user_id
 * @property-read User|null $author
 */
class ImprovementPlanCheckIn extends Model
{
    use HasTeamScope;

    protected $fillable = ['held_on', 'notes'];

    protected function casts(): array
    {
        return [
            'held_on' => 'date',
            'notes' => SensitiveText::class,
            'employee_comment' => SensitiveText::class,
            'commented_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ImprovementPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ImprovementPlan::class, 'improvement_plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
