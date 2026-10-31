<?php

namespace App\Models;

use App\Casts\SensitiveText;
use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something an employee asks HR (R-32 Phase 5). Seen by the person who raised it and by
 * the business's HR administrators — never by managers or colleagues. The body and the
 * answer are encrypted at rest.
 *
 * @property int $id
 * @property int $team_id
 * @property int $employee_id
 * @property int|null $requested_by
 * @property string $category
 * @property string $subject
 * @property string $body
 * @property string|null $response
 * @property string $status
 * @property Carbon|null $responded_at
 * @property Carbon $created_at
 * @property-read Employee $employee
 * @property-read User|null $responder
 */
class HrRequest extends Model
{
    use HasTeamScope;

    public const CATEGORIES = [
        'letter' => 'A letter (employment confirmation, reference)',
        'pay' => 'Pay or payslip question',
        'details' => 'Change to my details',
        'leave' => 'Leave question',
        'policy' => 'Question about a policy',
        'other' => 'Something else',
    ];

    public const STATUSES = ['open' => 'Open', 'answered' => 'Answered', 'closed' => 'Closed'];

    protected $fillable = ['category', 'subject', 'body'];

    protected function casts(): array
    {
        return [
            'body' => SensitiveText::class,
            'response' => SensitiveText::class,
            'responded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    public function reference(): string
    {
        return 'REQ-'.str_pad((string) $this->id, 3, '0', STR_PAD_LEFT);
    }
}
