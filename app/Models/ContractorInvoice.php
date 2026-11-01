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
 * @property Carbon|null $issued_on
 * @property int|null $engagement_milestone_id
 * @property string $source
 * @property int|null $submitted_by
 * @property string|null $file_path
 * @property string|null $file_name
 * @property string|null $file_mime
 * @property int|null $file_size
 * @property string|null $file_sha256
 * @property-read Engagement $engagement
 * @property-read EngagementMilestone|null $milestone
 */
class ContractorInvoice extends Model
{
    use HasTeamScope;

    /** Invoice files: PDFs and photos of paper invoices, checked by content. */
    public const MIMES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public const MAX_BYTES = 10 * 1024 * 1024;

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
            'issued_on' => 'date',
            'decided_at' => 'datetime',
            'file_size' => 'integer',
        ];
    }

    /** @return BelongsTo<EngagementMilestone, $this> */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(EngagementMilestone::class, 'engagement_milestone_id');
    }

    /** @return BelongsTo<Engagement, $this> */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }
}
