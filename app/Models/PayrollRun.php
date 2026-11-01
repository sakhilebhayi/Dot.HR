<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * HR's sign-off of one period's payroll inputs, with a fingerprint of what was signed off.
 *
 * @property int $id
 * @property int $team_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int $people
 * @property string $inputs_hash
 * @property string|null $note
 * @property int|null $signed_off_by
 * @property Carbon $signed_off_at
 * @property-read User|null $signer
 */
class PayrollRun extends Model
{
    use HasTeamScope;

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'signed_off_at' => 'datetime', 'people' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_off_by');
    }
}
