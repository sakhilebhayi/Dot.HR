<?php

namespace App\Models;

use App\Casts\SensitiveText;
use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One colleague's written 360 answer. Encrypted at rest; never shown to the person with a name.
 *
 * @property int $id
 * @property int $team_id
 * @property int $feedback_round_id
 * @property int $giver_user_id
 * @property string|null $keep_doing
 * @property string|null $do_differently
 * @property string|null $anything_else
 * @property Carbon|null $answered_at
 * @property Carbon|null $declined_at
 * @property-read FeedbackRound $round
 * @property-read User $giver
 */
class FeedbackResponse extends Model
{
    use HasTeamScope;

    public const QUESTIONS = [
        'keep_doing' => 'What should they keep doing?',
        'do_differently' => 'What could they do differently?',
        'anything_else' => 'Anything else that would help them?',
    ];

    protected function casts(): array
    {
        return [
            'keep_doing' => SensitiveText::class,
            'do_differently' => SensitiveText::class,
            'anything_else' => SensitiveText::class,
            'answered_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FeedbackRound, $this> */
    public function round(): BelongsTo
    {
        return $this->belongsTo(FeedbackRound::class, 'feedback_round_id');
    }

    /** @return BelongsTo<User, $this> */
    public function giver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'giver_user_id');
    }

    public function isPending(): bool
    {
        return $this->answered_at === null && $this->declined_at === null;
    }
}
