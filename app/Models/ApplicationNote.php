<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recruiter's private note on an application. Never shown to the candidate, never to
 * another business (team scope).
 *
 * @property int $id
 * @property string $body
 * @property-read User|null $author
 */
class ApplicationNote extends Model
{
    use HasTeamScope;

    protected $fillable = ['body'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
