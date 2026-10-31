<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An interview on an application. The time, mode, place and candidate note are shared with
 * the candidate; the recommendation and feedback never are.
 *
 * @property int $id
 * @property int $application_id
 * @property Carbon $starts_at
 * @property int $duration_minutes
 * @property string $mode
 * @property string|null $where
 * @property string|null $candidate_note
 * @property string $status
 * @property string|null $recommendation
 * @property string|null $feedback
 */
class Interview extends Model
{
    use HasTeamScope;

    public const MODES = ['in_person' => 'In person', 'phone' => 'Phone call', 'video' => 'Video call'];

    public const RECOMMENDATIONS = ['advance' => 'Advance', 'hold' => 'Hold', 'decline' => 'Do not advance'];

    protected $fillable = ['starts_at', 'duration_minutes', 'mode', 'where', 'candidate_note'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'duration_minutes' => 'integer'];
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function modeLabel(): string
    {
        return self::MODES[$this->mode] ?? $this->mode;
    }
}
