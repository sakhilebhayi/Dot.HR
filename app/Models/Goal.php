<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something a person is working towards (R-32 Phase 6b): a performance goal for the job, or a
 * development goal for their career. Progress is a plain status the person and their reviewer
 * keep current — not a score.
 *
 * @property int $id
 * @property int $team_id
 * @property int $employee_id
 * @property string $kind
 * @property string $title
 * @property string|null $detail
 * @property Carbon|null $due_on
 * @property string $status
 * @property-read Employee $employee
 */
class Goal extends Model
{
    use HasTeamScope;

    public const KINDS = ['performance' => 'For the job', 'development' => 'For their development'];

    public const STATUSES = ['open' => 'Not started', 'on_track' => 'On track', 'at_risk' => 'Needs help', 'done' => 'Done', 'dropped' => 'Dropped'];

    protected function casts(): array
    {
        return ['due_on' => 'date'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
