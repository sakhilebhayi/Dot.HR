<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One competency in the business's framework (brief §10), with three written levels. A level
 * describes the work, never the person, and is never turned into a number to add or compare.
 *
 * @property int $id
 * @property int $team_id
 * @property string|null $area
 * @property string $name
 * @property string|null $description
 * @property string $level_1
 * @property string $level_2
 * @property string $level_3
 * @property Carbon|null $retired_at
 */
class Competency extends Model
{
    use HasTeamScope;

    /** The three levels, named for what the work looks like. */
    public const LEVELS = [1 => 'Learning', 2 => 'Practising', 3 => 'Leading'];

    protected $fillable = ['area', 'name', 'description', 'level_1', 'level_2', 'level_3'];

    protected function casts(): array
    {
        return ['retired_at' => 'datetime'];
    }

    /** @param Builder<Competency> $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('retired_at');
    }

    public function levelText(int $level): string
    {
        return (string) match ($level) {
            1 => $this->level_1, 2 => $this->level_2, default => $this->level_3
        };
    }

    public static function levelName(?int $level): ?string
    {
        return $level ? (self::LEVELS[$level] ?? null) : null;
    }
}
