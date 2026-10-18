<?php

namespace App\Models;

use App\Casts\SensitiveText;
use App\Models\Concerns\HasTeamScope;
use App\Services\Hr\LeaveCalendar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A leave request tied to one employee. Team-scoped via `team_id` (denormalized from
 * the employee for simpler authorization checks).
 *
 * The free-text `reason` can incidentally carry health information (for sick leave), so
 * it is encrypted at rest, hidden from everyone but HR admins and the employee
 * themselves, and every look at it by someone else is written to the audit log.
 *
 * Statuses: pending -> approved | denied (a decision, final) or cancelled (by the
 * employee, while still pending). `type` is a snapshot of the leave type's slug taken
 * when the request was made, so renaming a type never rewrites history.
 *
 * @property int $id
 * @property int $team_id
 * @property int $employee_id
 * @property int|null $leave_type_id
 * @property string $type
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property string|null $half_day
 * @property string|null $reason
 * @property string $status
 * @property int|null $requested_by
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 */
class LeaveRequest extends Model
{
    use HasFactory;
    use HasTeamScope;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DENIED = 'denied';

    public const STATUS_CANCELLED = 'cancelled';

    /** Fields whose before/after values are safe to put in the audit log. */
    public const AUDIT_FIELDS = ['leave_type_id', 'type', 'start_date', 'end_date', 'half_day', 'status', 'reviewed_by'];

    protected $fillable = [
        'team_id',
        'employee_id',
        'leave_type_id',
        'type',
        'start_date',
        'end_date',
        'half_day',
        'reason',
        'status',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'reviewed_at' => 'datetime',
            'reason' => SensitiveText::class,
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** Includes a soft-deleted employee: leave history outlives the employee record until purged. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function typeLabel(): string
    {
        return $this->leaveType?->name ?? ucfirst(str_replace(['-', '_'], ' ', (string) $this->type));
    }

    /** Working days this request covers under the configured weekend and holidays (0.5 for a half day). */
    public function workingDays(): float
    {
        return app(LeaveCalendar::class)->workingDays($this->start_date, $this->end_date, $this->half_day);
    }

    /** Requests that hold the employee's dates: pending or approved. */
    public function scopeHoldingDates(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_APPROVED]);
    }

    /** Requests whose date range touches [start, end]. */
    public function scopeOverlapping(Builder $query, string $start, string $end): void
    {
        $query->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start);
    }
}
