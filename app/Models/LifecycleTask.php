<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step of a checklist. "employee" tasks are done by the person in their own workspace;
 * "hr" tasks by an HR administrator. An "acknowledge" task records that the person read a
 * policy, with the moment they confirmed it.
 *
 * @property int $id
 * @property int $team_id
 * @property int $lifecycle_checklist_id
 * @property string $title
 * @property string $owner
 * @property string $kind
 * @property string|null $body
 * @property Carbon|null $completed_at
 * @property int|null $completed_by
 * @property-read LifecycleChecklist $checklist
 * @property-read User|null $completer
 */
class LifecycleTask extends Model
{
    use HasTeamScope;

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    /** @return BelongsTo<LifecycleChecklist, $this> */
    public function checklist(): BelongsTo
    {
        return $this->belongsTo(LifecycleChecklist::class, 'lifecycle_checklist_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isDone(): bool
    {
        return $this->completed_at !== null;
    }
}
