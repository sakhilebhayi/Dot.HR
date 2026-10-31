<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recruiter told a current employee about an open role (R-32 Phase 7). The person decides
 * whether to apply; nothing about them changes.
 *
 * @property int $id
 * @property int $team_id
 * @property int $vacancy_id
 * @property int $employee_id
 * @property int|null $invited_by
 * @property-read Vacancy $vacancy
 * @property-read Employee $employee
 */
class InternalInvitation extends Model
{
    use HasTeamScope;

    /** @return BelongsTo<Vacancy, $this> */
    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
