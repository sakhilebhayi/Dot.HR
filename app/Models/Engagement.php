<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use App\Support\Hr\LocalTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A piece of work an external person does for the business (R-32 Phase 6b, brief §12): the
 * scope, the rate, the dates, its milestones and the invoices received against it. It sits on
 * a person record whose employment type is "contractor"; the label never settles the legal
 * classification, so the engagement surfaces signals worth a second look (signals()).
 *
 * @property int $id
 * @property int $team_id
 * @property int $employee_id
 * @property string $kind
 * @property string $title
 * @property string|null $scope
 * @property string $rate_basis
 * @property string|null $rate_amount
 * @property string $currency
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property string $status
 * @property int $renewals
 * @property string|null $end_reason
 * @property Carbon|null $ended_on
 * @property Carbon|null $reminded_at
 * @property-read Employee $employee
 * @property-read Collection<int, EngagementMilestone> $milestones
 * @property-read Collection<int, ContractorInvoice> $invoices
 */
class Engagement extends Model
{
    use HasTeamScope;

    public const KINDS = [
        'contractor' => 'Independent contractor',
        'freelancer' => 'Freelancer',
        'consultant' => 'Consultant',
        'temporary' => 'Temporary worker',
        'outsourced' => 'Outsourced team',
        'service_provider' => 'Service provider',
    ];

    public const RATE_BASES = [
        'hourly' => 'per hour',
        'daily' => 'per day',
        'monthly' => 'per month',
        'fixed' => 'fixed fee',
        'milestone' => 'per milestone',
    ];

    public const END_REASONS = [
        'completed' => 'The work is done',
        'not_renewed' => 'Not renewed',
        'ended_by_business' => 'Ended early by the business',
        'ended_by_contractor' => 'Ended early by the contractor',
        'other' => 'Another reason',
    ];

    public const SOON_DAYS = 30;

    public const AUDIT_FIELDS = ['status', 'starts_on', 'ends_on', 'renewals', 'end_reason', 'ended_on'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'ended_on' => 'date',
            'reminded_at' => 'datetime',
            'renewals' => 'integer',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<EngagementMilestone, $this> */
    public function milestones(): HasMany
    {
        return $this->hasMany(EngagementMilestone::class)->orderByRaw('due_on is null')->orderBy('due_on')->orderBy('id');
    }

    /** @return HasMany<ContractorInvoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(ContractorInvoice::class)->latest('received_on');
    }

    public function rate(): string
    {
        if ($this->rate_amount === null) {
            return 'Rate not recorded';
        }

        return $this->currency.' '.number_format((float) $this->rate_amount, 2).' '.self::RATE_BASES[$this->rate_basis];
    }

    public function endsSoon(): bool
    {
        return $this->status === 'active'
            && $this->ends_on !== null
            && $this->ends_on->lte(LocalTime::today()->addDays(self::SOON_DAYS));
    }

    /**
     * Facts that suggest the working relationship may look like employment. They are prompts
     * to take advice, never a determination: how the work is actually done decides that.
     *
     * @return list<string>
     */
    public function signals(): array
    {
        $signals = [];

        if ($this->renewals >= 2) {
            $signals[] = 'Renewed '.$this->renewals.' times — a long chain of renewals can look like ongoing employment.';
        }

        $end = $this->ended_on ?? $this->ends_on ?? LocalTime::today();
        if ($this->starts_on->diffInMonths($end) >= 12) {
            $signals[] = 'Running for a year or more.';
        }

        $onRoster = Shift::query()
            ->whereHas('employees', fn ($q) => $q->whereKey($this->employee_id))
            ->where('starts_at', '>=', LocalTime::now()->subDays(90))
            ->exists();
        if ($onRoster) {
            $signals[] = 'Scheduled on the staff roster in the last 90 days — set hours under your direction are a sign of employment.';
        }

        return $signals;
    }
}
