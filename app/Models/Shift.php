<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A block of work at a time and place (R-32 Phase 6a). Shifts may cross midnight; a shift's
 * people are attached through shift_assignments.
 *
 * @property int $id
 * @property int $team_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int $break_minutes
 * @property string|null $title
 * @property string|null $location
 * @property string|null $notes
 * @property-read Collection<int, Employee> $employees
 */
class Shift extends Model
{
    use HasTeamScope;

    protected $fillable = ['starts_at', 'ends_at', 'break_minutes', 'title', 'location', 'notes'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'break_minutes' => 'integer'];
    }

    /** @return BelongsToMany<Employee, $this> */
    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'shift_assignments')->withPivot('team_id')->withTimestamps();
    }

    /** Paid minutes: the span less the unpaid break, never below zero. */
    public function paidMinutes(): int
    {
        return max(0, (int) $this->starts_at->diffInMinutes($this->ends_at) - $this->break_minutes);
    }

    public function window(): string
    {
        $end = $this->ends_at->isSameDay($this->starts_at) ? $this->ends_at->format('H:i') : $this->ends_at->format('D H:i');

        return $this->starts_at->format('H:i').'–'.$end;
    }
}
