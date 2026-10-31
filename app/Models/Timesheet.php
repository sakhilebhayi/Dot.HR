<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's week (Monday start), built from their attendance and submitted for approval.
 * Once approved the week is locked: its attendance cannot be changed.
 *
 * @property int $id
 * @property int $team_id
 * @property int $employee_id
 * @property Carbon $week_start
 * @property string $status
 * @property int $worked_minutes
 * @property int $overtime_minutes
 * @property Carbon|null $submitted_at
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property-read Employee $employee
 * @property-read User|null $decider
 */
class Timesheet extends Model
{
    use HasTeamScope;

    public const STATUSES = ['draft' => 'Not submitted', 'submitted' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Sent back'];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'worked_minutes' => 'integer',
            'overtime_minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public static function hours(int $minutes): string
    {
        return intdiv($minutes, 60).'h'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }
}
