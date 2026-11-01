<?php

namespace App\Models;

use App\Casts\SensitiveText;
use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A certificate of service (BCEA s42): who, for whom, from when to when, in what job, at what
 * pay at the end, and the reason for leaving only if the person asked for it.
 *
 * @property int $id
 * @property int $team_id
 * @property int $employee_id
 * @property string $employer_name
 * @property string $employer_address
 * @property string $job_title
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property string $remuneration
 * @property string|null $reason
 * @property Carbon $issued_at
 * @property int|null $issued_by
 * @property-read Employee $employee
 * @property-read User|null $issuer
 */
class ServiceCertificate extends Model
{
    use HasTeamScope;

    protected $fillable = ['employer_name', 'employer_address', 'job_title', 'start_date', 'end_date', 'remuneration', 'reason'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'issued_at' => 'datetime', 'remuneration' => SensitiveText::class];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
