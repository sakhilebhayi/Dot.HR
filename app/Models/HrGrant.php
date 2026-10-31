<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A business-level HR capability held by one member of one company (R-32 Phase 3).
 * Only counts while the holder still belongs to the team — HrAccess checks that.
 *
 * @property int $team_id
 * @property int $user_id
 * @property string $capability
 * @property int|null $granted_by
 */
class HrGrant extends Model
{
    public const RECRUITER = 'recruiter';

    public const HIRING_MANAGER = 'hiring_manager';

    public const SUPERVISOR = 'supervisor';

    /** @var array<string, string> capability => what the person may do, in plain words */
    public const CAPABILITIES = [
        self::RECRUITER => 'Recruiter — publishes roles, works the hiring pipeline, contacts candidates',
        self::HIRING_MANAGER => 'Hiring manager — reviews candidates and gives interview feedback for their roles',
        self::SUPERVISOR => 'Supervisor — builds the schedule, records attendance and approves timesheets',
    ];

    protected $fillable = ['team_id', 'user_id', 'capability', 'granted_by'];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
