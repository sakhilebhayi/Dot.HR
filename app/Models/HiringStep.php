<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A business's own named step inside one of the fixed open stages (a phone screen, an
 * assessment, a reference check). Internal: candidates never see steps.
 *
 * @property int $id
 * @property int $team_id
 * @property string $stage
 * @property string $name
 * @property int $position
 * @property Carbon|null $retired_at
 */
class HiringStep extends Model
{
    use HasTeamScope;

    /** The stages a step can sit in. New is the inbox; hired, rejected and withdrawn are ends. */
    public const STAGES = ['review', 'shortlisted', 'interview', 'offer'];

    protected $fillable = ['stage', 'name', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'retired_at' => 'datetime'];
    }

    /** @param Builder<HiringStep> $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('retired_at')->orderBy('position')->orderBy('id');
    }
}
