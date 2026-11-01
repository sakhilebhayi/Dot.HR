<?php

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamDeleted;
use Laravel\Jetstream\Events\TeamUpdated;
use Laravel\Jetstream\Team as JetstreamTeam;

/**
 * @property int|null $weekly_ordinary_hours above it, timesheet time is flagged as overtime (R-32 Phase 6a)
 * @property string|null $timezone the business's own IANA time zone; null uses HR_LOCAL_TIMEZONE (LocalTime)
 */
class Team extends JetstreamTeam
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'personal_team',
    ];

    /**
     * The event map for the model.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => TeamCreated::class,
        'updated' => TeamUpdated::class,
        'deleted' => TeamDeleted::class,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
            'weekly_ordinary_hours' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Every team starts with the default leave types (editable at /leave-types).
        static::created(fn (Team $team) => LeaveType::seedDefaults($team));
    }

    /**
     * The owner and every member with the admin role — the people HR notices go to. One query
     * on the membership table, so callers never load each member's teams to ask.
     *
     * @return Collection<int, User>
     */
    public function administrators(): Collection
    {
        $ids = DB::table('team_user')->where('team_id', $this->id)->where('role', 'admin')->pluck('user_id')->push($this->user_id);

        return User::query()->whereIn('id', $ids->unique()->all())->get();
    }

    /** @return array<int, string> member user id => Jetstream role key */
    public function memberRoles(): array
    {
        return DB::table('team_user')->where('team_id', $this->id)->pluck('role', 'user_id')->all();
    }

    public function leaveTypes(): HasMany
    {
        return $this->hasMany(LeaveType::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }
}
