<?php

namespace App\Models;

use App\Casts\SensitiveText;
use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A voluntary exit interview. The answers are encrypted and read by HR administrators only;
 * they count towards anonymous patterns only when the person agreed to that.
 *
 * @property int $id
 * @property int $team_id
 * @property int $employee_id
 * @property Carbon $offered_at
 * @property string|null $mode
 * @property list<string>|null $reasons
 * @property string|null $answers
 * @property string|null $would_return
 * @property bool $share_anonymously
 * @property Carbon|null $completed_at
 * @property Carbon|null $declined_at
 * @property-read Employee $employee
 */
class ExitInterview extends Model
{
    use HasTeamScope;

    /** Answers are only summarised when at least this many people agreed, so nobody can be picked out. */
    public const ANONYMITY_FLOOR = 5;

    public const REASONS = [
        'pay' => 'Pay or benefits',
        'growth' => 'Few chances to grow',
        'management' => 'How I was managed',
        'workload' => 'Workload or hours',
        'culture' => 'How people treat each other here',
        'recognition' => 'Not feeling valued',
        'new_role' => 'A better offer elsewhere',
        'personal' => 'Personal or family reasons',
        'relocation' => 'Moving away',
        'other' => 'Something else',
    ];

    public const RETURN = ['yes' => 'Yes', 'unsure' => 'Not sure', 'no' => 'No'];

    /** The open questions, in the order they are asked. */
    public const QUESTIONS = [
        'worked_well' => 'What worked well for you here?',
        'improve' => 'What should we change?',
        'anything_else' => 'Anything else you want us to know?',
    ];

    protected function casts(): array
    {
        return [
            'offered_at' => 'datetime',
            'reasons' => 'array',
            'answers' => SensitiveText::class,
            'share_anonymously' => 'boolean',
            'completed_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /** @return array<string, string> question key => answer */
    public function answerList(): array
    {
        $decoded = $this->answers ? json_decode($this->answers, true) : [];

        return is_array($decoded) ? array_filter($decoded, 'is_string') : [];
    }

    public function isOpen(): bool
    {
        return $this->completed_at === null && $this->declined_at === null;
    }
}
