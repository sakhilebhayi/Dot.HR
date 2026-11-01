<?php

namespace App\Models;

use App\Casts\SensitiveText;
use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line on a case's timeline. Append-only: there is no edit and no delete; a correction is
 * a new entry. Internal unless shared, and the body is encrypted at rest.
 *
 * @property int $id
 * @property int $team_id
 * @property int $hr_case_id
 * @property string $kind
 * @property string $body
 * @property bool $shared
 * @property int|null $author_user_id
 * @property bool $by_employee
 * @property Carbon|null $received_at
 * @property Carbon $created_at
 * @property-read User|null $author
 * @property-read HrCase $case
 */
class HrCaseEntry extends Model
{
    use HasTeamScope;

    public const UPDATED_AT = null;

    public const KINDS = [
        'note' => 'Note',
        'statement' => 'Statement',
        'notice' => 'Notice',
        'hearing' => 'Hearing',
        'outcome' => 'Outcome',
        'appeal' => 'Appeal',
        'response' => 'Their response',
        'update' => 'Update',
    ];

    /** What a handler may write by hand; the rest are written by the steps of the process. */
    public const WRITABLE = ['note', 'statement', 'update'];

    protected $fillable = ['kind', 'body', 'shared'];

    protected function casts(): array
    {
        return ['body' => SensitiveText::class, 'shared' => 'boolean', 'by_employee' => 'boolean', 'received_at' => 'datetime'];
    }

    /** @return BelongsTo<HrCase, $this> */
    public function case(): BelongsTo
    {
        return $this->belongsTo(HrCase::class, 'hr_case_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? 'Entry';
    }
}
