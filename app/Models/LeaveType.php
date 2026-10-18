<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A kind of leave a team offers and how it is counted. `annual_days` null means the
 * type is not tracked against a balance (sick and unpaid by default). When it is set,
 * `enforce_balance` decides whether a request that would exceed the balance is refused
 * or merely allowed to run the balance negative.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $slug
 * @property float|null $annual_days
 * @property float $carry_over_days
 * @property bool $is_paid
 * @property bool $enforce_balance
 */
class LeaveType extends Model
{
    use HasTeamScope;

    protected $fillable = [
        'team_id',
        'name',
        'slug',
        'annual_days',
        'carry_over_days',
        'is_paid',
        'enforce_balance',
    ];

    protected function casts(): array
    {
        return [
            'annual_days' => 'float',
            'carry_over_days' => 'float',
            'is_paid' => 'boolean',
            'enforce_balance' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function isTracked(): bool
    {
        return $this->annual_days !== null;
    }

    /** Creates the default types for a team, leaving any that already exist untouched. */
    public static function seedDefaults(Team $team): void
    {
        // Seeding is not an admin decision, so it is not audited (withoutEvents).
        self::withoutEvents(function () use ($team): void {
            foreach (config('hr.default_leave_types', []) as $type) {
                self::withoutTeamScope(fn () => self::firstOrCreate(
                    ['team_id' => $team->id, 'slug' => $type['slug']],
                    collect($type)->except('slug')->all(),
                ));
            }
        });
    }

    /** A slug no other type in the team uses. */
    public static function uniqueSlug(int $teamId, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'leave';
        $slug = $base;
        $n = 2;

        while (self::withoutTeamScope(fn () => self::where('team_id', $teamId)->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists())) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
