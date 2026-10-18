<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use HasTeams;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /** @var array<int, Employee|null> */
    private array $hrEmployeeCache = [];

    /**
     * The employee record linked to this account in $team, if any (cached per instance).
     * Soft-deleted employees do not count. Looked up under the explicit team-scope opt-out
     * because it is already keyed by team and user.
     */
    public function hrEmployee(Team $team): ?Employee
    {
        if (! array_key_exists($team->id, $this->hrEmployeeCache)) {
            $this->hrEmployeeCache[$team->id] = Employee::withoutTeamScope(
                fn () => Employee::where('team_id', $team->id)->where('user_id', $this->id)->first()
            );
        }

        return $this->hrEmployeeCache[$team->id];
    }

    public function forgetHrEmployee(): void
    {
        $this->hrEmployeeCache = [];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
