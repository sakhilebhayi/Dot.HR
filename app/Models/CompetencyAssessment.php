<?php

namespace App\Models;

use App\Casts\SensitiveText;
use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person on one competency: where they place themselves, with evidence, and the level HR
 * agreed with them, with a note. Seen by the person and HR administrators only.
 *
 * @property int $id
 * @property int $team_id
 * @property int $employee_id
 * @property int $competency_id
 * @property int|null $self_level
 * @property string|null $self_evidence
 * @property Carbon|null $self_at
 * @property int|null $agreed_level
 * @property string|null $agreed_note
 * @property Carbon|null $agreed_at
 * @property-read Competency $competency
 */
class CompetencyAssessment extends Model
{
    use HasTeamScope;

    protected function casts(): array
    {
        return [
            'self_level' => 'integer',
            'agreed_level' => 'integer',
            'self_evidence' => SensitiveText::class,
            'agreed_note' => SensitiveText::class,
            'self_at' => 'datetime',
            'agreed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Competency, $this> */
    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }
}
