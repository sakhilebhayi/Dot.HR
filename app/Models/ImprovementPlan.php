<?php

namespace App\Models;

use App\Casts\SensitiveText;
use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A performance improvement plan (brief §10 and §15; LRA Schedule 8, items 8 and 9). It says
 * what is below standard, what the standard is, what support is given, and for how long, then
 * records the check-ins and the outcome. No score or rating of the person, as with Reviews.
 * HR administrators and the supporting manager manage it; the person sees it once it is shared.
 *
 * @property int $id
 * @property int $team_id
 * @property int $employee_id
 * @property int|null $manager_user_id
 * @property int|null $hr_case_id
 * @property string $title
 * @property string $concern
 * @property string $standard
 * @property string $support
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property int $check_in_days
 * @property string $status
 * @property Carbon|null $shared_at
 * @property Carbon|null $acknowledged_at
 * @property string|null $employee_response
 * @property int $extensions
 * @property string|null $outcome
 * @property string|null $outcome_notes
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 * @property-read Employee $employee
 * @property-read User|null $manager
 * @property-read HrCase|null $case
 */
class ImprovementPlan extends Model
{
    use HasTeamScope;

    public const STATUSES = [
        'draft' => 'Draft, not shared',
        'active' => 'In progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    public const OUTCOMES = [
        'met' => 'The standard is now met',
        'not_met' => 'The standard is still not met',
    ];

    /** Shorter than this, the time to improve may not be reasonable (a prompt, not a rule of law). */
    public const SHORT_PERIOD_DAYS = 30;

    protected $fillable = ['title', 'concern', 'standard', 'support', 'starts_on', 'ends_on', 'check_in_days'];

    protected function casts(): array
    {
        return [
            'concern' => SensitiveText::class,
            'standard' => SensitiveText::class,
            'support' => SensitiveText::class,
            'employee_response' => SensitiveText::class,
            'outcome_notes' => SensitiveText::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'check_in_days' => 'integer',
            'extensions' => 'integer',
            'shared_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /** @return BelongsTo<HrCase, $this> */
    public function case(): BelongsTo
    {
        return $this->belongsTo(HrCase::class, 'hr_case_id');
    }

    /** @return HasMany<ImprovementPlanObjective, $this> */
    public function objectives(): HasMany
    {
        return $this->hasMany(ImprovementPlanObjective::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<ImprovementPlanCheckIn, $this> */
    public function checkIns(): HasMany
    {
        return $this->hasMany(ImprovementPlanCheckIn::class)->orderByDesc('held_on')->orderByDesc('id');
    }

    public function reference(): string
    {
        return 'IP-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['draft', 'active'], true);
    }

    public function periodDays(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on) + 1;
    }

    /** The next check-in: the cadence counted from the last one held, or from the start. */
    public function nextCheckIn(): ?Carbon
    {
        if ($this->status !== 'active') {
            return null;
        }
        $last = $this->checkIns()->value('held_on');
        $from = $last ? Carbon::parse($last) : $this->starts_on;

        return $from->copy()->addDays($this->check_in_days)->min($this->ends_on);
    }
}
