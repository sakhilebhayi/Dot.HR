<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A 360 feedback round about one person. Colleagues answer in writing; HR sees who wrote what
 * (so misuse can be dealt with); the person reads the words without names, once at least
 * ANONYMITY_FLOOR people have answered and the round is shared.
 *
 * @property int $id
 * @property int $team_id
 * @property int $employee_id
 * @property int|null $requested_by
 * @property string|null $focus
 * @property Carbon $due_on
 * @property string $status
 * @property Carbon|null $shared_at
 * @property Carbon $created_at
 * @property-read Employee $employee
 */
class FeedbackRound extends Model
{
    use HasTeamScope;

    public const ANONYMITY_FLOOR = 3;

    public const MAX_GIVERS = 8;

    public const STATUSES = ['open' => 'Collecting answers', 'shared' => 'Shared with them', 'closed' => 'Closed'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'shared_at' => 'datetime'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /** @return HasMany<FeedbackResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(FeedbackResponse::class);
    }

    public function reference(): string
    {
        return 'FB-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    public function answeredCount(): int
    {
        return $this->responses->whereNotNull('answered_at')->count();
    }
}
