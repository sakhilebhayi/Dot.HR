<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A role a business publishes (R-32 Phase 4). The work type and arrangement are keys of the
 * same registries a person's profile uses, so a preference and a role speak the same words.
 *
 * @property int $id
 * @property int $team_id
 * @property string $title
 * @property string $description
 * @property string|null $responsibilities
 * @property string|null $requirements
 * @property array<int, string>|null $skills
 * @property string $work_type
 * @property string $arrangement
 * @property string|null $location
 * @property string $pay_basis
 * @property string|null $pay_min
 * @property string|null $pay_max
 * @property string $currency
 * @property string|null $hours
 * @property int $openings
 * @property Carbon|null $starts_on
 * @property Carbon|null $closes_on
 * @property array<int, string>|null $screening_questions
 * @property string $status
 * @property Carbon|null $published_at
 * @property-read Team $team
 */
class Vacancy extends Model
{
    use HasTeamScope;

    public const STATUSES = [
        'draft' => 'Draft',
        'published' => 'Open for applications',
        'paused' => 'Paused',
        'closed' => 'Closed',
    ];

    /** How the work is paid. "undisclosed" is an employer's choice, shown honestly as such. */
    public const PAY_BASES = [
        'salary' => 'Monthly salary',
        'hourly' => 'Hourly rate',
        'daily' => 'Day rate',
        'project' => 'Project fee',
        'commission' => 'Commission',
        'unpaid' => 'Unpaid (volunteering)',
        'undisclosed' => 'Not disclosed',
    ];

    public const PAY_UNITS = ['salary' => 'a month', 'hourly' => 'an hour', 'daily' => 'a day', 'project' => 'for the project'];

    protected $fillable = [
        'title', 'description', 'responsibilities', 'requirements', 'skills', 'work_type', 'arrangement',
        'location', 'pay_basis', 'pay_min', 'pay_max', 'currency', 'hours', 'openings', 'starts_on',
        'closes_on', 'screening_questions',
    ];

    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'screening_questions' => 'array',
            'starts_on' => 'date',
            'closes_on' => 'date',
            'published_at' => 'datetime',
            'openings' => 'integer',
        ];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasMany<Application, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /** Published and not past its closing date: the only state anyone outside the business sees. */
    public function isOpen(): bool
    {
        return $this->status === 'published' && ($this->closes_on === null || ! $this->closes_on->isBefore(today()));
    }

    /**
     * The public board's query: open roles of every business, explicitly across teams.
     *
     * @param  Builder<Vacancy>  $query
     */
    public function scopeOpenToPublic(Builder $query): void
    {
        $query->where('status', 'published')
            ->where(fn (Builder $q) => $q->whereNull('closes_on')->orWhereDate('closes_on', '>=', today()));
    }

    public function payLabel(): string
    {
        if (in_array($this->pay_basis, ['undisclosed', 'commission', 'unpaid'], true) || $this->pay_min === null) {
            return self::PAY_BASES[$this->pay_basis] ?? 'Not disclosed';
        }

        $fmt = fn (string $amount) => ($this->currency === 'ZAR' ? 'R' : $this->currency.' ').number_format((float) $amount, 0, '.', ' ');
        $range = $this->pay_max !== null && (float) $this->pay_max > (float) $this->pay_min
            ? $fmt($this->pay_min).' – '.$fmt($this->pay_max)
            : $fmt($this->pay_min);

        return $range.' '.(self::PAY_UNITS[$this->pay_basis] ?? '');
    }

    public function workTypeLabel(): string
    {
        return ProfessionalProfile::WORK_TYPES[$this->work_type] ?? $this->work_type;
    }

    public function arrangementLabel(): string
    {
        return ProfessionalProfile::ARRANGEMENTS[$this->arrangement] ?? $this->arrangement;
    }

    public function reference(): string
    {
        return 'VAC-'.str_pad((string) $this->id, 3, '0', STR_PAD_LEFT);
    }

    /** @return HasMany<HiringCost, $this> */
    public function hiringCosts(): HasMany
    {
        return $this->hasMany(HiringCost::class)->orderBy('incurred_on');
    }
}
