<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A record of an invoice received from a contractor. Dot.HR moves no money: "Paid" is what
 * the business records after paying it elsewhere.
 *
 * @property int $id
 * @property int $team_id
 * @property int $engagement_id
 * @property string $reference
 * @property string $amount
 * @property Carbon $received_on
 * @property string $status
 * @property string|null $note
 * @property Carbon|null $decided_at
 * @property-read Engagement $engagement
 */
class ContractorInvoice extends Model
{
    use HasTeamScope;

    public const STATUSES = ['received' => 'Received', 'approved' => 'Approved for payment', 'disputed' => 'Disputed', 'paid' => 'Paid'];

    /** Which statuses each status may move to. */
    public const MOVES = [
        'received' => ['approved', 'disputed'],
        'disputed' => ['approved'],
        'approved' => ['paid', 'disputed'],
        'paid' => [],
    ];

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Engagement, $this> */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }
}
