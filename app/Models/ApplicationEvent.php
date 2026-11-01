<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of an application's history, append-only (Application::record()).
 *
 * @property int $id
 * @property string $kind
 * @property array<string, mixed>|null $data
 * @property Carbon $created_at
 * @property-read User|null $actor
 */
class ApplicationEvent extends Model
{
    use HasTeamScope;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** The history in plain words. */
    public function describe(): string
    {
        $d = $this->data ?? [];

        return match ($this->kind) {
            'applied' => 'Applied',
            'stage' => 'Moved to '.(Application::STAGES[$d['to'] ?? ''] ?? ($d['to'] ?? '?')),
            'step' => isset($d['step']) ? 'Step: '.$d['step'] : 'Step cleared',
            'interview.scheduled' => 'Interview scheduled for '.($d['when'] ?? ''),
            'interview.cancelled' => 'Interview cancelled',
            'interview.feedback' => 'Interview feedback recorded'.(isset($d['recommendation']) ? ' — '.$d['recommendation'] : ''),
            'offer.drafted' => 'Offer drafted',
            'offer.sent' => 'Offer sent',
            'offer.withdrawn' => 'Offer withdrawn',
            'offer.accepted' => 'Offer accepted',
            'offer.declined' => 'Offer declined',
            'hired' => 'Hired — employee record created',
            'withdrawn' => 'Candidate withdrew',
            default => $this->kind,
        };
    }
}
