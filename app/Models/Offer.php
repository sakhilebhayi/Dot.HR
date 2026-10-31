<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An employment offer (R-32 Phase 4). Drafted and sent by the business, accepted or declined
 * by the candidate; acceptance creates the employee record exactly once
 * (App\Services\Recruitment\HireFromOffer).
 *
 * @property int $id
 * @property int $team_id
 * @property int $application_id
 * @property string $job_title
 * @property string $pay_basis
 * @property string|null $pay_amount
 * @property string $currency
 * @property Carbon $starts_on
 * @property string|null $terms
 * @property string $status
 * @property Carbon|null $sent_at
 * @property Carbon|null $responded_at
 * @property int|null $employee_id
 * @property-read Application $application
 */
class Offer extends Model
{
    use HasTeamScope;

    public const STATUSES = [
        'draft' => 'Draft',
        'sent' => 'Sent — awaiting the candidate',
        'accepted' => 'Accepted',
        'declined' => 'Declined',
        'withdrawn' => 'Withdrawn',
    ];

    protected $fillable = ['job_title', 'pay_basis', 'pay_amount', 'currency', 'starts_on', 'terms'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function payLabel(): string
    {
        if ($this->pay_amount === null || in_array($this->pay_basis, ['undisclosed', 'commission', 'unpaid'], true)) {
            return Vacancy::PAY_BASES[$this->pay_basis] ?? '—';
        }

        $amount = ($this->currency === 'ZAR' ? 'R' : $this->currency.' ').number_format((float) $this->pay_amount, 2, '.', ' ');

        return $amount.' '.(Vacancy::PAY_UNITS[$this->pay_basis] ?? '');
    }
}
