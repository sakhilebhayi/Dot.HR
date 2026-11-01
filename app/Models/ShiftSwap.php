<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use App\Support\Hr\LocalTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A shift handed to a colleague, or exchanged for one of theirs (brief §8). It changes the
 * schedule only once the colleague has accepted and a supervisor has approved.
 *
 * @property int $id
 * @property int $team_id
 * @property int $shift_id
 * @property int $from_employee_id
 * @property int|null $to_employee_id
 * @property int|null $other_shift_id
 * @property string|null $note
 * @property string $status
 * @property Carbon|null $accepted_at
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property Carbon $created_at
 * @property-read Shift $shift
 * @property-read Shift|null $otherShift
 * @property-read Employee $from
 * @property-read Employee|null $to
 */
class ShiftSwap extends Model
{
    use HasTeamScope;

    public const STATUSES = [
        'offered' => 'Waiting for a colleague',
        'accepted' => 'Waiting for a supervisor',
        'approved' => 'Approved',
        'declined' => 'Declined',
        'cancelled' => 'Withdrawn',
        'expired' => 'Expired',
    ];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<Shift, $this> */
    public function otherShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'other_shift_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_employee_id')->withTrashed();
    }

    /** @return BelongsTo<Employee, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_employee_id')->withTrashed();
    }

    public function isSwap(): bool
    {
        return $this->other_shift_id !== null;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['offered', 'accepted'], true);
    }

    /** A swap is only possible while every shift in it is still ahead. */
    public function hasStarted(): bool
    {
        $now = LocalTime::now();

        return $this->shift->starts_at->lte($now) || ($this->otherShift && $this->otherShift->starts_at->lte($now));
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
