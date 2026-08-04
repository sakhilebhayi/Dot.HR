<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Employment record — the platform's most person-sensitive model (wiki.md §2).
 * Deliberately minimal PII surface: name and work contact only. No national
 * ID/passport number, no salary, no medical/disability data. If those fields
 * are ever added, they need real encryption-at-rest and access-control
 * review first (see Dot.Brain's os/17-Security.md and platforms/dot-hr.md §7
 * field-classification register) — this model does not implement that yet.
 *
 * Never exposed to the shared Dot.Brain knowledge graph at the individual
 * level; only aggregate/cohort rollups are ever intended to leave this
 * platform (wiki.md §5-6).
 */
class Employee extends Model
{
    use HasFactory;
    use HasTeamScope;

    protected $fillable = [
        'team_id',
        'position_id',
        'user_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'employment_type',
        'status',
        'start_date',
        'end_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * The linked platform login, if this employee also has ecosystem access.
     * Nullable — not every employee record implies an account.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
