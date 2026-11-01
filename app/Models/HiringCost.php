<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money spent filling one role: advertising, an agency fee, assessments, travel. Recorded by
 * hand; Dot.HR moves no money.
 *
 * @property int $id
 * @property int $team_id
 * @property int $vacancy_id
 * @property string $kind
 * @property string|null $description
 * @property string $amount
 * @property string $currency
 * @property Carbon $incurred_on
 * @property-read Vacancy $vacancy
 */
class HiringCost extends Model
{
    use HasTeamScope;

    public const KINDS = [
        'advertising' => 'Advertising',
        'agency' => 'Recruitment agency',
        'assessments' => 'Assessments and checks',
        'travel' => 'Travel for interviews',
        'other' => 'Other',
    ];

    protected $fillable = ['kind', 'description', 'amount', 'currency', 'incurred_on'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'incurred_on' => 'date'];
    }

    /** @return BelongsTo<Vacancy, $this> */
    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class);
    }
}
