<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A training budget: an amount for a period, for the whole business or one department.
 *
 * @property int $id
 * @property int $team_id
 * @property string|null $department
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $amount
 * @property string $currency
 * @property string|null $note
 */
class TrainingBudget extends Model
{
    use HasTeamScope;

    protected $fillable = ['department', 'starts_on', 'ends_on', 'amount', 'currency', 'note'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'amount' => 'decimal:2'];
    }

    public function label(): string
    {
        return ($this->department ?: 'The whole business').', '.$this->starts_on->format('j M Y').' to '.$this->ends_on->format('j M Y');
    }
}
