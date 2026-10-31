<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use App\Support\Hr\LocalTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A licence, ticket, medical or course an employee holds, as HR recorded and (optionally)
 * verified it (R-32 Phase 6a). Expiry drives the readiness view and a one-off reminder.
 *
 * @property int $id
 * @property int $team_id
 * @property int $employee_id
 * @property string $name
 * @property string|null $issuer
 * @property Carbon|null $issued_on
 * @property Carbon|null $expires_on
 * @property Carbon|null $verified_at
 * @property Carbon|null $reminded_at
 * @property-read Employee $employee
 * @property-read User|null $verifier
 */
class EmployeeCertification extends Model
{
    use HasTeamScope;

    public const SOON_DAYS = 30;

    protected $fillable = ['name', 'issuer', 'issued_on', 'expires_on'];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'expires_on' => 'date', 'verified_at' => 'datetime', 'reminded_at' => 'datetime'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** valid | soon | expired */
    public function state(): string
    {
        if ($this->expires_on === null) {
            return 'valid';
        }

        if ($this->expires_on->isBefore(LocalTime::today())) {
            return 'expired';
        }

        return $this->expires_on->lte(LocalTime::today()->addDays(self::SOON_DAYS)) ? 'soon' : 'valid';
    }
}
