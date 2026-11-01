<?php

namespace App\Models;

use App\Casts\SensitiveText;
use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An employee relations case (R-32 brief §15): a grievance, a disciplinary case or a workplace
 * incident record. Confidential by construction: the account of what happened and the outcome
 * reasons are encrypted at rest, the timeline is append-only, and who may open a case is decided
 * by HrAccess::canSeeCase() alone — never managers, never the person a grievance is about.
 *
 * @property int $id
 * @property int $team_id
 * @property string $type
 * @property string $category
 * @property int|null $employee_id
 * @property int|null $respondent_employee_id
 * @property string $title
 * @property string $summary
 * @property Carbon|null $occurred_on
 * @property string $status
 * @property int|null $handler_user_id
 * @property bool $restricted
 * @property string $source
 * @property int|null $opened_by
 * @property Carbon|null $hearing_at
 * @property string|null $hearing_place
 * @property string|null $hearing_chair
 * @property string|null $outcome
 * @property string|null $outcome_reasons
 * @property Carbon|null $decided_at
 * @property Carbon|null $warning_expires_on
 * @property Carbon|null $appealed_at
 * @property string|null $appeal_outcome
 * @property Carbon|null $appeal_decided_at
 * @property Carbon|null $reported_externally_on
 * @property Carbon|null $closed_at
 * @property Carbon $created_at
 * @property-read Employee|null $employee
 * @property-read Employee|null $respondent
 * @property-read User|null $handler
 */
class HrCase extends Model
{
    use HasTeamScope;

    public const TYPES = [
        'grievance' => 'Grievance',
        'disciplinary' => 'Disciplinary case',
        'incident' => 'Workplace incident',
    ];

    /** @var array<string, array<string, string>> type => category => label */
    public const CATEGORIES = [
        'grievance' => [
            'treatment' => 'How I am treated at work',
            'harassment' => 'Harassment',
            'discrimination' => 'Unfair discrimination',
            'pay' => 'Pay or benefits',
            'conditions' => 'Working conditions or hours',
            'safety' => 'Health and safety',
            'other' => 'Something else',
        ],
        'disciplinary' => [
            'misconduct' => 'Misconduct',
            'attendance' => 'Attendance or timekeeping',
            'policy' => 'Breach of a policy or rule',
            'performance' => 'Poor work performance',
        ],
        'incident' => [
            'injury' => 'Injury at work',
            'near_miss' => 'Near miss',
            'property' => 'Damage to property or equipment',
            'security' => 'Theft or a security incident',
            'other' => 'Other incident',
        ],
    ];

    public const STATUSES = [
        'open' => 'Open',
        'investigating' => 'Investigating',
        'hearing' => 'Hearing set',
        'decided' => 'Decided',
        'appealed' => 'On appeal',
        'closed' => 'Closed',
        'withdrawn' => 'Withdrawn',
    ];

    /** @var array<string, array<string, string>> type => outcome => label */
    public const OUTCOMES = [
        'disciplinary' => [
            'not_proven' => 'Not proven, no sanction',
            'counselling' => 'Counselling',
            'verbal_warning' => 'Verbal warning',
            'written_warning' => 'Written warning',
            'final_warning' => 'Final written warning',
            'dismissal' => 'Dismissal',
            'other' => 'Another sanction',
        ],
        'grievance' => [
            'upheld' => 'Upheld',
            'partly_upheld' => 'Partly upheld',
            'not_upheld' => 'Not upheld',
            'resolved' => 'Resolved by agreement',
        ],
        'incident' => [
            'recorded' => 'Recorded, no further action',
            'actions' => 'Recorded, actions taken',
        ],
    ];

    /** Outcomes that are warnings: they stay on the record until they expire. */
    public const WARNINGS = ['verbal_warning', 'written_warning', 'final_warning'];

    public const APPEAL_OUTCOMES = [
        'dismissed' => 'Appeal dismissed: the outcome stands',
        'varied' => 'Outcome changed on appeal',
        'upheld' => 'Appeal upheld: the outcome is set aside',
    ];

    protected $fillable = ['type', 'category', 'title', 'summary', 'occurred_on'];

    protected function casts(): array
    {
        return [
            'summary' => SensitiveText::class,
            'outcome_reasons' => SensitiveText::class,
            'occurred_on' => 'date',
            'restricted' => 'boolean',
            'hearing_at' => 'datetime',
            'decided_at' => 'datetime',
            'warning_expires_on' => 'date',
            'appealed_at' => 'datetime',
            'appeal_decided_at' => 'datetime',
            'reported_externally_on' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /** @return BelongsTo<Employee, $this> */
    public function respondent(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'respondent_employee_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handler_user_id');
    }

    /** @return HasMany<HrCaseEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(HrCaseEntry::class)->orderBy('created_at')->orderBy('id');
    }

    public function reference(): string
    {
        $prefix = ['grievance' => 'GR', 'disciplinary' => 'DC', 'incident' => 'IN'][$this->type] ?? 'HR';

        return $prefix.'-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? 'Case';
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->type][$this->category] ?? 'Other';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function outcomeLabel(): ?string
    {
        return $this->outcome ? (self::OUTCOMES[$this->type][$this->outcome] ?? $this->outcome) : null;
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, ['closed', 'withdrawn'], true);
    }

    public function isWarning(): bool
    {
        return in_array($this->outcome, self::WARNINGS, true) && $this->appeal_outcome !== 'upheld';
    }

    /** A warning that still counts: not set aside on appeal and not yet expired. */
    public function warningActive(): bool
    {
        return $this->isWarning() && ($this->warning_expires_on === null || $this->warning_expires_on->endOfDay()->isFuture());
    }

    /**
     * A work injury is reported to the Compensation Fund within seven days of the employer being
     * told (COIDA s39). The case is opened when HR is told, so the date runs from then.
     */
    public function externalReportDue(): ?Carbon
    {
        return $this->type === 'incident' && $this->category === 'injury' && $this->reported_externally_on === null
            ? $this->created_at->copy()->addDays(7)->startOfDay()
            : null;
    }

    /** Poor performance is an incapacity matter: guidance and time to improve come before any sanction. */
    public function isIncapacity(): bool
    {
        return $this->type === 'disciplinary' && $this->category === 'performance';
    }
}
