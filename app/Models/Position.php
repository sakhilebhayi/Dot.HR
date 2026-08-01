<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A job/position definition — describes work, not a worker. Publishable-shaped
 * data per wiki.md §2 (no individual is named here), but still team-scoped
 * since it is owned by a specific team's org structure.
 */
class Position extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'title',
        'department',
        'description',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
