<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Training a person asked for, was booked on, or completed (R-32 Phase 6b, brief §10). When
 * completed training carries a certification name it becomes a certification record, so the
 * readiness view (Phase 6a) sees it.
 *
 * @property int $id
 * @property int $team_id
 * @property int $employee_id
 * @property string $title
 * @property string|null $provider
 * @property string $kind
 * @property string $status
 * @property Carbon|null $starts_on
 * @property Carbon|null $completed_on
 * @property string|null $hours
 * @property string|null $certification_name
 * @property Carbon|null $certification_expires_on
 * @property string|null $reason
 * @property int|null $requested_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property-read Employee $employee
 */
class TrainingRecord extends Model
{
    use HasTeamScope;

    public const KINDS = ['internal' => 'Internal', 'external' => 'External course'];

    public const STATUSES = ['requested' => 'Asked for', 'planned' => 'Booked', 'completed' => 'Completed', 'declined' => 'Declined'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'completed_on' => 'date',
            'certification_expires_on' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
