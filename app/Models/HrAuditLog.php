<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One row per access or change. Append-only: the model refuses to update or delete a
 * row it has loaded. That is a guard against code paths, not against someone with
 * database access; restrict UPDATE/DELETE on this table for the application's database
 * role if you need that guarantee. Rows hold field names and non-sensitive values only.
 */
class HrAuditLog extends Model
{
    use HasTeamScope;

    public const UPDATED_AT = null;

    protected $table = 'hr_audit_log';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw new LogicException('The HR audit log is append-only: rows cannot be updated or deleted.');
        };

        static::updating($refuse);
        static::deleting($refuse);
        static::saving(function (HrAuditLog $row) use ($refuse): void {
            if ($row->exists) {
                $refuse();
            }
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
