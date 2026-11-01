<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person confirming they read a policy or accepted a document: who, when, and the name they
 * typed. An electronic acceptance record — not an advanced electronic signature.
 *
 * @property int $id
 * @property int $team_id
 * @property int $hr_document_id
 * @property int $employee_id
 * @property int|null $user_id
 * @property string|null $typed_name
 * @property Carbon $acknowledged_at
 */
class HrDocumentAcknowledgement extends Model
{
    use HasTeamScope;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }

    /** @return BelongsTo<HrDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(HrDocument::class, 'hr_document_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }
}
