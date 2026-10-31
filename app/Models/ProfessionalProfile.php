<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A person's professional identity (R-32 Phase 3): owned by the user, carried across
 * every workspace. Who may read it is decided in one place,
 * App\Policies\ProfessionalProfilePolicy, from the visibility the person chose.
 *
 * @property int $id
 * @property int $user_id
 * @property string $handle
 * @property string|null $headline
 * @property string|null $about
 * @property string|null $location
 * @property string $availability
 * @property array<int, string>|null $work_arrangements
 * @property array<int, string>|null $work_types
 * @property string $visibility
 * @property-read User $user
 * @property-read Collection<int, ProfileSkill> $skills
 * @property-read Collection<int, ProfileEntry> $entries
 * @property-read Collection<int, Team> $blockedTeams
 */
class ProfessionalProfile extends Model
{
    /** Who may see the profile, most open first. */
    public const VISIBILITY = [
        'public' => 'Public — anyone with the link, signed in or not',
        'employers' => 'Employers on Dot.HR — recruiters of businesses you have not blocked',
        'applications' => 'Only businesses I apply to',
        'private' => 'Private — only me',
    ];

    public const AVAILABILITY = [
        'looking' => 'Actively looking',
        'open' => 'Open to the right offer',
        'not_looking' => 'Not looking right now',
    ];

    public const ARRANGEMENTS = ['on_site' => 'On-site', 'hybrid' => 'Hybrid', 'remote' => 'Remote'];

    /**
     * The kinds of work a person can say they want (brief §4). One registry, used by the
     * profile now and by vacancies in Phase 4, so a preference and a role always speak
     * the same words.
     */
    public const WORK_TYPES = [
        'permanent' => 'Permanent',
        'full_time' => 'Full-time',
        'part_time' => 'Part-time',
        'fixed_term' => 'Fixed-term contract',
        'temporary' => 'Temporary',
        'casual' => 'Casual',
        'seasonal' => 'Seasonal',
        'freelance' => 'Freelance',
        'contractor' => 'Independent contractor',
        'consulting' => 'Consulting',
        'project' => 'Project-based',
        'internship' => 'Internship',
        'learnership' => 'Learnership',
        'apprenticeship' => 'Apprenticeship',
        'graduate' => 'Graduate programme',
        'volunteer' => 'Volunteering',
        'commission' => 'Commission-based',
        'shift' => 'Shift work',
        'hourly' => 'Hourly or day-rate',
        'executive' => 'Executive or specialist appointment',
        'trades' => 'Skilled trades and operational',
        'domestic' => 'Domestic and household',
    ];

    protected $fillable = [
        'headline', 'about', 'location', 'availability', 'work_arrangements', 'work_types', 'visibility',
    ];

    protected function casts(): array
    {
        return [
            'work_arrangements' => 'array',
            'work_types' => 'array',
        ];
    }

    /** The person's profile, created empty and private the first time they open it. */
    public static function forUser(User $user): self
    {
        // user_id and handle are never mass-assignable; only this method sets them.
        return self::query()->where('user_id', $user->id)->first()
            ?? self::query()->forceCreate([
                'user_id' => $user->id,
                'handle' => self::freshHandle(),
                'visibility' => 'private',
                'availability' => 'not_looking',
            ]);
    }

    public static function freshHandle(): string
    {
        do {
            $handle = Str::lower(Str::random(10));
        } while (self::query()->where('handle', $handle)->exists());

        return $handle;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ProfileSkill, $this> */
    public function skills(): HasMany
    {
        return $this->hasMany(ProfileSkill::class)->orderBy('position');
    }

    /** @return HasMany<ProfileEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(ProfileEntry::class)
            ->orderByDesc('is_current')
            ->orderByDesc('started_on')
            ->orderBy('position');
    }

    /** @return BelongsToMany<Team, $this> */
    public function blockedTeams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'profile_blocked_teams')->withTimestamps();
    }

    /** @return Collection<int, ProfileEntry> */
    public function entriesOf(string $kind): Collection
    {
        return $this->entries->where('kind', $kind)->values();
    }

    /**
     * How complete the profile is, from parts the person can see and fill — never a
     * judgement of the person. Each counts once.
     *
     * @return array{done: int, total: int, missing: list<string>}
     */
    public function completeness(): array
    {
        $parts = [
            'a headline' => filled($this->headline),
            'a few lines about you' => filled($this->about),
            'your location' => filled($this->location),
            'at least three skills' => $this->skills->count() >= 3,
            'your work history' => $this->entriesOf('experience')->isNotEmpty(),
            'a qualification' => $this->entriesOf('education')->isNotEmpty() || $this->entriesOf('certification')->isNotEmpty(),
        ];

        $missing = array_keys(array_filter($parts, fn (bool $done) => ! $done));

        return ['done' => count($parts) - count($missing), 'total' => count($parts), 'missing' => $missing];
    }
}
