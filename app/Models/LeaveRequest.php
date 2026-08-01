<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A leave request tied to one employee. Team-scoped via `team_id` (denormalized
 * from the employee for simpler, single-join authorization checks). The
 * free-text `reason` field can incidentally carry health information for
 * `sick` leave even though there is no structured medical field — treat it
 * as sensitive; see the migration docblock and wiki.md §9 roadmap items.
 */
class LeaveRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'employee_id',
        'type',
        'start_date',
        'end_date',
        'reason',
        'status',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
