<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One person's application to one role (R-32 Phase 4). Team-scoped like every employer
 * record; the applicant reads their own through an explicit, user-filtered bypass
 * (App\Http\Controllers\Me\MyApplicationsController), never through the scope.
 *
 * @property int $id
 * @property int $team_id
 * @property int $vacancy_id
 * @property int $user_id
 * @property string $stage
 * @property int|null $hiring_step_id
 * @property string|null $cover_note
 * @property array<int, string>|null $answers
 * @property Carbon|null $stage_changed_at
 * @property Carbon $created_at
 * @property-read Vacancy $vacancy
 * @property-read User $user
 * @property-read HiringStep|null $step
 */
class Application extends Model
{
    use HasTeamScope;

    /** The employer's pipeline, in order. Hired, rejected and withdrawn are where it ends. */
    public const STAGES = [
        'new' => 'New',
        'review' => 'In review',
        'shortlisted' => 'Shortlisted',
        'interview' => 'Interview',
        'offer' => 'Offer',
        'hired' => 'Hired',
        'rejected' => 'Not progressing',
        'withdrawn' => 'Withdrawn',
    ];

    public const OPEN_STAGES = ['new', 'review', 'shortlisted', 'interview', 'offer'];

    /** Stages a recruiter may move an application to by hand (offer and hired follow the offer). */
    public const MOVABLE_TO = ['review', 'shortlisted', 'interview', 'rejected'];

    /**
     * What the candidate sees. Internal distinctions (new vs in review vs shortlisted) stay
     * inside the business; the candidate gets a plain, honest state.
     */
    public const CANDIDATE_STAGES = [
        'new' => 'Received',
        'review' => 'In review',
        'shortlisted' => 'In review',
        'interview' => 'Interview',
        'offer' => 'Offer made',
        'hired' => 'Hired',
        'rejected' => 'Not progressing',
        'withdrawn' => 'Withdrawn',
    ];

    protected $fillable = ['cover_note', 'answers'];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'stage_changed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Vacancy, $this> */
    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ApplicationNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(ApplicationNote::class)->latest();
    }

    /** @return HasMany<ApplicationEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ApplicationEvent::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    /** @return HasMany<Interview, $this> */
    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class)->orderBy('starts_at');
    }

    /** @return HasMany<Offer, $this> */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class)->latest();
    }

    public function isOpen(): bool
    {
        return in_array($this->stage, self::OPEN_STAGES, true);
    }

    /** @return BelongsTo<HiringStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(HiringStep::class, 'hiring_step_id');
    }

    public function stageLabel(): string
    {
        return self::STAGES[$this->stage] ?? $this->stage;
    }

    public function candidateStageLabel(): string
    {
        return self::CANDIDATE_STAGES[$this->stage] ?? $this->stage;
    }

    public function reference(): string
    {
        return 'APP-'.str_pad((string) $this->id, 3, '0', STR_PAD_LEFT);
    }

    /** Append to the history. Team and application are taken from this record, never from input. */
    public function record(string $kind, ?int $actorId, array $data = []): ApplicationEvent
    {
        $event = new ApplicationEvent;
        $event->forceFill([
            'team_id' => $this->team_id,
            'application_id' => $this->id,
            'actor_id' => $actorId,
            'kind' => $kind,
            'data' => $data === [] ? null : $data,
            'created_at' => now(),
        ])->save();

        return $event;
    }
}
