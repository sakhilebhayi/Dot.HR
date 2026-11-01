<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An employment document on a person's record, or (with no employee) a company policy. The file
 * itself is encrypted in Dot.HR's private storage; this row holds what it is and who may see it.
 * HR administrators see everything; the employee sees what was shared with them, what they
 * submitted, and every live policy; managers see none of it.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $employee_id
 * @property string $kind
 * @property string $title
 * @property string $file_name
 * @property string $mime
 * @property int $size
 * @property string $path
 * @property string $sha256
 * @property string $source
 * @property bool $shared
 * @property bool $requires_acceptance
 * @property int|null $uploaded_by
 * @property Carbon|null $withdrawn_at
 * @property Carbon $created_at
 * @property-read Employee|null $employee
 * @property-read User|null $uploader
 */
class HrDocument extends Model
{
    use HasTeamScope;

    /** What HR files on someone's record. */
    public const KINDS = [
        'contract' => 'Employment contract',
        'amendment' => 'Contract amendment',
        'offer_letter' => 'Offer letter',
        'qualification' => 'Qualification or certificate',
        'identity' => 'Identity or work permit copy',
        'letter' => 'Letter',
        'other' => 'Other document',
    ];

    /** What an employee may hand in themselves. */
    public const SUBMITTABLE = ['qualification', 'identity', 'other'];

    public const POLICY = 'policy';

    /** Formats people actually send, checked by content, not by name. */
    public const MIMES = [
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/msword' => 'doc',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public const MAX_BYTES = 10 * 1024 * 1024;

    protected $fillable = ['kind', 'title', 'shared', 'requires_acceptance'];

    protected function casts(): array
    {
        return ['shared' => 'boolean', 'requires_acceptance' => 'boolean', 'withdrawn_at' => 'datetime', 'size' => 'integer'];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return HasMany<HrDocumentAcknowledgement, $this> */
    public function acknowledgements(): HasMany
    {
        return $this->hasMany(HrDocumentAcknowledgement::class);
    }

    /** @param Builder<HrDocument> $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('withdrawn_at');
    }

    public function isPolicy(): bool
    {
        return $this->employee_id === null;
    }

    public function kindLabel(): string
    {
        return $this->isPolicy() ? 'Company policy' : (self::KINDS[$this->kind] ?? 'Document');
    }

    public function sizeLabel(): string
    {
        return $this->size >= 1048576 ? round($this->size / 1048576, 1).' MB' : max(1, (int) round($this->size / 1024)).' KB';
    }

    /** Whether this employee may open it (HR administrators are decided by the policy, not here). */
    public function visibleToEmployee(Employee $employee): bool
    {
        if ($this->withdrawn_at !== null) {
            return false;
        }

        return $this->isPolicy() || ($this->employee_id === $employee->id && ($this->shared || $this->source === 'employee'));
    }
}
