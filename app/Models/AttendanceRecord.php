<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One stretch of work as clocked (R-32 Phase 6a). Only times are recorded — no location, no
 * device fingerprint, no biometric data. A supervisor may record or correct a stretch; that
 * is marked as such and audited.
 *
 * @property int $id
 * @property int $team_id
 * @property int $employee_id
 * @property int|null $shift_id
 * @property Carbon $clock_in_at
 * @property Carbon|null $clock_out_at
 * @property int $break_minutes
 * @property string $source
 * @property string|null $note
 * @property-read Employee $employee
 */
class AttendanceRecord extends Model
{
    use HasTeamScope;

    protected $fillable = ['clock_in_at', 'clock_out_at', 'break_minutes', 'note'];

    protected function casts(): array
    {
        return ['clock_in_at' => 'datetime', 'clock_out_at' => 'datetime', 'break_minutes' => 'integer'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function workedMinutes(): int
    {
        if ($this->clock_out_at === null) {
            return 0;
        }

        return max(0, (int) $this->clock_in_at->diffInMinutes($this->clock_out_at) - $this->break_minutes);
    }
}
