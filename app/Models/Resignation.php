<?php

namespace App\Models;

use App\Casts\SensitiveText;
use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Notice of resignation, given in writing from My work (BCEA s37(4)). HR acknowledges receipt;
 * a resignation is not approved or refused. It is withdrawn only by agreement.
 *
 * @property int $id
 * @property int $team_id
 * @property int $employee_id
 * @property int|null $user_id
 * @property Carbon $given_at
 * @property Carbon $last_day
 * @property int $minimum_notice_days
 * @property string|null $reason
 * @property string|null $message
 * @property string $status
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $agreed_last_day
 * @property Carbon|null $withdrawal_requested_at
 * @property string|null $withdrawal_note
 * @property Carbon|null $withdrawal_decided_at
 * @property int|null $lifecycle_checklist_id
 * @property-read Employee $employee
 * @property-read LifecycleChecklist|null $checklist
 */
class Resignation extends Model
{
    use HasTeamScope;

    /** Why, as a category the person may choose to give; the rest is theirs to say or not. */
    public const REASONS = [
        'new_role' => 'A new job',
        'pay' => 'Pay or benefits',
        'growth' => 'Growth or career change',
        'relocation' => 'Moving away',
        'studies' => 'Studies',
        'personal' => 'Personal or family reasons',
        'health' => 'Health',
        'retirement' => 'Retirement',
        'other' => 'Something else',
    ];

    public const STATUSES = ['given' => 'Waiting for HR', 'acknowledged' => 'Received by HR', 'withdrawn' => 'Withdrawn by agreement'];

    protected $fillable = ['last_day', 'reason', 'message'];

    protected function casts(): array
    {
        return [
            'given_at' => 'datetime',
            'last_day' => 'date',
            'agreed_last_day' => 'date',
            'minimum_notice_days' => 'integer',
            'message' => SensitiveText::class,
            'withdrawal_note' => SensitiveText::class,
            'acknowledged_at' => 'datetime',
            'withdrawal_requested_at' => 'datetime',
            'withdrawal_decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /** @return BelongsTo<LifecycleChecklist, $this> */
    public function checklist(): BelongsTo
    {
        return $this->belongsTo(LifecycleChecklist::class, 'lifecycle_checklist_id');
    }

    public function reference(): string
    {
        return 'RS-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    /** The day the person will leave: the agreed day once HR confirmed one, otherwise the one they gave. */
    public function leavingOn(): Carbon
    {
        return $this->agreed_last_day ?? $this->last_day;
    }

    /** Days of notice the last day gives, counted from the day notice was given. */
    public function noticeDays(): int
    {
        return (int) $this->given_at->copy()->startOfDay()->diffInDays($this->leavingOn());
    }

    /**
     * BCEA s37(1) minimum notice by length of service: one week up to six months, two weeks up
     * to a year, four weeks after that. A contract or collective agreement may ask for longer.
     */
    public static function minimumNoticeDays(?Carbon $startDate, Carbon $on): int
    {
        if ($startDate === null) {
            return 28;
        }
        if ($on->lte($startDate->copy()->addMonthsNoOverflow(6))) {
            return 7;
        }

        return $on->lt($startDate->copy()->addYear()) ? 14 : 28;
    }
}
