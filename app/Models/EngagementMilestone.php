<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A deliverable inside an engagement: the contractor marks it delivered, HR approves it or
 * sends it back with a note.
 *
 * @property int $id
 * @property int $team_id
 * @property int $engagement_id
 * @property string $title
 * @property Carbon|null $due_on
 * @property string|null $amount
 * @property string $status
 * @property Carbon|null $submitted_at
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property-read Engagement $engagement
 */
class EngagementMilestone extends Model
{
    use HasTeamScope;

    public const STATUSES = ['open' => 'Open', 'submitted' => 'Delivered — waiting', 'approved' => 'Approved', 'rejected' => 'Sent back'];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Engagement, $this> */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }
}
