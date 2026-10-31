<?php

namespace App\Models;

use App\Casts\SensitiveText;
use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A written review (R-32 Phase 6b, brief §10): the person's own reflection, then the
 * reviewer's feedback, shared with the person, who acknowledges it and may answer. There is no
 * score, rating or ranking anywhere — by design, in keeping with "without ranking anyone".
 * The three texts are encrypted at rest and only the person, their reviewer and the
 * business's HR administrators can read them.
 *
 * @property int $id
 * @property int $team_id
 * @property int $review_cycle_id
 * @property int $employee_id
 * @property int $reviewer_id
 * @property string $status
 * @property string|null $self_assessment
 * @property bool $self_skipped
 * @property string|null $feedback
 * @property string|null $response
 * @property Carbon|null $self_done_at
 * @property Carbon|null $shared_at
 * @property Carbon|null $acknowledged_at
 * @property-read ReviewCycle $cycle
 * @property-read Employee $employee
 * @property-read User $reviewer
 */
class Review extends Model
{
    use HasTeamScope;

    public const STATUSES = [
        'self' => 'Waiting for their own reflection',
        'reviewer' => 'Waiting for the reviewer',
        'shared' => 'Shared — waiting for them to read it',
        'acknowledged' => 'Complete',
    ];

    public const AUDIT_FIELDS = ['status', 'reviewer_id'];

    protected function casts(): array
    {
        return [
            'self_assessment' => SensitiveText::class,
            'feedback' => SensitiveText::class,
            'response' => SensitiveText::class,
            'self_skipped' => 'boolean',
            'self_done_at' => 'datetime',
            'shared_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ReviewCycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ReviewCycle::class, 'review_cycle_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
