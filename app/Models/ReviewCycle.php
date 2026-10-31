<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A round of written reviews (R-32 Phase 6b), e.g. "Second half 2026": the people in it and
 * who reviews each of them.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property Carbon|null $closes_on
 * @property string $status
 * @property-read Collection<int, Review> $reviews
 */
class ReviewCycle extends Model
{
    use HasTeamScope;

    protected function casts(): array
    {
        return ['closes_on' => 'date'];
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
