<?php

namespace App\Models;

use App\Casts\SensitiveText;
use App\Models\Concerns\HasTeamScope;
use App\Support\Hr\EmailIndex;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Employment record, the platform's most person-sensitive model (wiki.md section 2).
 *
 * Personal data held: first and last name, work email, work phone (email and phone are
 * encrypted at rest; email also has a keyed blind index for uniqueness and lookup).
 * No national ID or passport number, no salary, no medical or disability data. If those
 * are ever added they need their own review first.
 *
 * Deleting is soft: the row, and its leave history, stay until `hr:purge-expired` removes
 * them after the retention period. "On leave" is not stored: it is computed from approved
 * leave covering the day (see isOnLeave / displayStatus), so it can never go stale.
 *
 * Never exposed to the shared Dot.Brain knowledge graph at the individual level.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $position_id
 * @property int|null $user_id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $email
 * @property string|null $email_hash
 * @property string|null $phone
 * @property string $employment_type
 * @property string|null $emergency_contact_name
 * @property string|null $emergency_contact_phone
 * @property string $status
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property Carbon|null $deleted_at
 */
class Employee extends Model
{
    use HasFactory;
    use HasTeamScope;
    use SoftDeletes;

    /** Fields whose before/after values are safe to put in the audit log (names are logged for all fields). */
    public const AUDIT_FIELDS = ['position_id', 'employment_type', 'status', 'start_date', 'end_date', 'user_id'];

    protected $fillable = [
        'team_id',
        'position_id',
        'user_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'employment_type',
        'status',
        'start_date',
        'end_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'email' => SensitiveText::class,
            'phone' => SensitiveText::class,
            'emergency_contact_name' => SensitiveText::class,
            'emergency_contact_phone' => SensitiveText::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Employee $employee): void {
            if ($employee->isDirty('email') || ! $employee->exists) {
                $employee->email_hash = EmailIndex::hash($employee->email);
            }
        });
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * The linked platform login, if this employee also has ecosystem access.
     * Nullable: not every employee record implies an account.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Is there approved leave covering $on (default: today)? Computed, never stored.
     * A `withOnLeaveFlag()` query supplies it without one query per row.
     */
    public function isOnLeave(?CarbonInterface $on = null): bool
    {
        if ($on === null && array_key_exists('is_on_leave', $this->attributes)) {
            return (bool) $this->attributes['is_on_leave'];
        }

        $day = ($on ?? now())->toDateString();

        return $this->leaveRequests()
            ->withoutGlobalScope('team') // already constrained to this employee, who was loaded under the scope
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day)
            ->exists();
    }

    /** active | on_leave | terminated. The legacy stored `on_leave` value is ignored. */
    public function displayStatus(?CarbonInterface $on = null): string
    {
        if ($this->status === 'terminated') {
            return 'terminated';
        }

        return $this->isOnLeave($on) ? 'on_leave' : 'active';
    }

    public function scopeWithOnLeaveFlag(Builder $query): void
    {
        $query->addSelect([
            'is_on_leave' => LeaveRequest::withoutGlobalScope('team')
                ->selectRaw('1')
                ->whereColumn('leave_requests.employee_id', 'employees.id')
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', now()->toDateString())
                ->whereDate('end_date', '>=', now()->toDateString())
                ->limit(1),
        ]);
    }

    /** Employed and not away on $on (default: today). */
    public function scopeAtWork(Builder $query, ?CarbonInterface $on = null): void
    {
        $day = ($on ?? now())->toDateString();

        $query->where('employees.status', '!=', 'terminated')->whereNotIn('employees.id', LeaveRequest::withoutGlobalScope('team')
            ->select('employee_id')
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day));
    }

    /** Employees with approved leave covering $on (default: today). */
    public function scopeOnLeaveOn(Builder $query, ?CarbonInterface $on = null): void
    {
        $day = ($on ?? now())->toDateString();

        $query->where('employees.status', '!=', 'terminated')->whereIn('employees.id', LeaveRequest::withoutGlobalScope('team')
            ->select('employee_id')
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day));
    }

    /** @return HasMany<LifecycleChecklist, $this> */
    public function checklists(): HasMany
    {
        return $this->hasMany(LifecycleChecklist::class)->latest();
    }
}
