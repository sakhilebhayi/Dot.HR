<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One line of a person's history: a job, a qualification, or a certification/licence.
 *
 * @property int $id
 * @property string $kind
 * @property string $title
 * @property string|null $organisation
 * @property Carbon|null $started_on
 * @property Carbon|null $ended_on
 * @property bool $is_current
 * @property Carbon|null $expires_on
 * @property string|null $description
 */
class ProfileEntry extends Model
{
    public const KINDS = [
        'experience' => 'Work history',
        'education' => 'Education',
        'certification' => 'Certifications and licences',
    ];

    protected $fillable = [
        'kind', 'title', 'organisation', 'started_on', 'ended_on', 'is_current', 'expires_on', 'description', 'position',
    ];

    protected function casts(): array
    {
        return [
            'started_on' => 'date',
            'ended_on' => 'date',
            'expires_on' => 'date',
            'is_current' => 'boolean',
        ];
    }

    /** "Mar 2021 – now", "2019 – 2022", or nothing when no dates were given. */
    public function period(): string
    {
        if (! $this->started_on) {
            return '';
        }

        $end = $this->is_current ? 'now' : ($this->ended_on?->format('M Y') ?? '');

        return trim($this->started_on->format('M Y').($end !== '' ? ' – '.$end : ''));
    }

    public function hasLapsed(): bool
    {
        return $this->expires_on !== null && $this->expires_on->isPast();
    }
}
