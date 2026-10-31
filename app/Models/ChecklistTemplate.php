<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;

/**
 * A business's own default checklist for a process. Until a business writes one, Dot.HR's
 * defaults apply (ChecklistTemplate::DEFAULTS) — neutral steps, not legal advice, and
 * editable to fit the business and its jurisdiction.
 *
 * @property int $id
 * @property int $team_id
 * @property string $process
 * @property list<array{title: string, owner: string, kind: string, body?: string|null}> $items
 */
class ChecklistTemplate extends Model
{
    use HasTeamScope;

    public const DEFAULTS = [
        'onboarding' => [
            ['title' => 'Check your personal details on your employee record', 'owner' => 'employee', 'kind' => 'task'],
            ['title' => 'Add an emergency contact', 'owner' => 'employee', 'kind' => 'task'],
            ['title' => 'Read the leave policy', 'owner' => 'employee', 'kind' => 'acknowledge', 'body' => 'Leave is requested in Dot.HR and decided by an HR administrator. Your balance and the leave year are shown on your home page.'],
            ['title' => 'Prepare and share the employment contract', 'owner' => 'hr', 'kind' => 'task'],
            ['title' => 'Set up payroll from the start date', 'owner' => 'hr', 'kind' => 'task'],
            ['title' => 'Arrange equipment and system access', 'owner' => 'hr', 'kind' => 'task'],
            ['title' => 'Name a manager and plan the first week', 'owner' => 'hr', 'kind' => 'task'],
        ],
        'offboarding' => [
            ['title' => 'Hand over your work and open items', 'owner' => 'employee', 'kind' => 'task'],
            ['title' => 'Return equipment, keys and access cards', 'owner' => 'employee', 'kind' => 'task'],
            ['title' => 'Confirm the last working day in writing', 'owner' => 'hr', 'kind' => 'task'],
            ['title' => 'Collect equipment and remove system access', 'owner' => 'hr', 'kind' => 'task'],
            ['title' => 'Prepare final pay and a certificate of service', 'owner' => 'hr', 'kind' => 'task'],
            ['title' => 'Offer an exit interview', 'owner' => 'hr', 'kind' => 'task'],
        ],
    ];

    protected $fillable = ['process', 'items'];

    protected function casts(): array
    {
        return ['items' => 'array'];
    }

    /**
     * The items a business uses for $process: its own if written, otherwise the defaults.
     *
     * @return list<array{title: string, owner: string, kind: string, body?: string|null}>
     */
    public static function itemsFor(int $teamId, string $process): array
    {
        $own = self::withoutTeamScope(fn () => self::query()->where('team_id', $teamId)->where('process', $process)->first());

        return $own ? array_values($own->items) : self::DEFAULTS[$process];
    }
}
