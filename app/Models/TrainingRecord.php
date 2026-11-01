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
 * @property string|null $cost
 * @property string $currency
 * @property string $paid_by
 * @property string|null $sponsor
 * @property-read Employee $employee
 */
class TrainingRecord extends Model
{
    use HasTeamScope;

    public const KINDS = ['internal' => 'Internal', 'external' => 'External course'];

    public const STATUSES = ['requested' => 'Asked for', 'planned' => 'Booked', 'completed' => 'Completed', 'declined' => 'Declined'];

    /** Who pays. Only training the business pays counts against a training budget. */
    public const PAID_BY = ['business' => 'The business', 'employee' => 'The person', 'sponsor' => 'A sponsor or funder'];

    /** The cost as shown: "R 4 500.00" style, or null when none was recorded. */
    public function costLabel(): ?string
    {
        if ($this->cost === null) {
            return null;
        }

        return $this->currency.' '.number_format((float) $this->cost, 2, '.', ' ').($this->paid_by !== 'business' ? ', paid by '.mb_strtolower(self::PAID_BY[$this->paid_by] ?? $this->paid_by).($this->sponsor ? ' ('.$this->sponsor.')' : '') : '');
    }

    /** The day the cost belongs to: when it was completed, else when it starts, else when it was decided. */
    public function costDate(): ?Carbon
    {
        return $this->completed_on ?? $this->starts_on ?? $this->decided_at?->copy()->startOfDay();
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'completed_on' => 'date',
            'certification_expires_on' => 'date',
            'decided_at' => 'datetime',
            'cost' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
