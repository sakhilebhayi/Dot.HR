<?php

namespace App\Services\Hr;

use App\Models\Competency;
use App\Models\CompetencyAssessment;
use App\Models\Employee;
use App\Models\LearningPathStep;
use App\Models\Position;
use App\Models\PositionCompetency;
use App\Models\Team;
use App\Models\User;
use App\Notifications\LifecycleNotification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The business's competency framework and each person's place in it (brief §10). Levels are
 * descriptions, never scores: nothing here adds, averages or compares people. A gap is where a
 * person's agreed level is below what their position expects, shown to them and HR only, as a
 * place to grow, with training one click away.
 */
final class Competencies
{
    public function save(Team $team, array $data, ?Competency $competency = null): Competency
    {
        $competency ??= new Competency;
        $competency->fill(collect($data)->only(['area', 'name', 'description', 'level_1', 'level_2', 'level_3'])->map(fn ($v) => is_string($v) ? trim($v) : $v)->all());
        $competency->forceFill(['team_id' => $team->id])->save();
        Audit::record($competency->wasRecentlyCreated ? 'competency.created' : 'competency.updated', $competency, ['name', 'level_1', 'level_2', 'level_3'], [], $team->id);

        return $competency;
    }

    public function retire(Competency $competency): void
    {
        $competency->forceFill(['retired_at' => now()])->save();
        Audit::record('competency.retired', $competency, ['retired_at'], [], $competency->team_id);
    }

    /** @param array<int, string|int|null> $levels competency id => level 1-3, or empty to drop it */
    public function setMatrixRow(Position $position, array $levels): void
    {
        foreach ($levels as $competencyId => $level) {
            $level = (int) $level;
            if ($level === 0) {
                PositionCompetency::query()->where('position_id', $position->id)->where('competency_id', $competencyId)->delete();

                continue;
            }
            abort_unless(array_key_exists($level, Competency::LEVELS) && Competency::query()->whereKey($competencyId)->exists(), 422);
            $cell = PositionCompetency::query()->where('position_id', $position->id)->where('competency_id', $competencyId)->first() ?? new PositionCompetency;
            $cell->forceFill(['team_id' => $position->team_id, 'position_id' => $position->id, 'competency_id' => (int) $competencyId, 'expected_level' => $level])->save();
        }
        Audit::record('competency.matrix_changed', $position, ['competencies'], [], $position->team_id);
    }

    public function selfAssess(Employee $employee, Competency $competency, int $level, ?string $evidence): CompetencyAssessment
    {
        $this->check($employee, $competency, $level);
        $row = $this->row($employee, $competency);
        $row->forceFill(['self_level' => $level, 'self_evidence' => $evidence !== null && trim($evidence) !== '' ? trim($evidence) : null, 'self_at' => now()])->save();
        Audit::record('competency.self_assessed', $row, ['self_level', 'self_evidence'], ['competency_id' => $competency->id], $employee->team_id);

        return $row;
    }

    public function agree(Employee $employee, Competency $competency, int $level, ?string $note, User $by): CompetencyAssessment
    {
        $this->check($employee, $competency, $level);
        $row = $this->row($employee, $competency);
        $row->forceFill(['agreed_level' => $level, 'agreed_note' => $note !== null && trim($note) !== '' ? trim($note) : null, 'agreed_by' => $by->id, 'agreed_at' => now()])->save();
        Audit::record('competency.agreed', $row, ['agreed_level', 'agreed_note'], ['competency_id' => $competency->id], $employee->team_id);

        if ($employee->user_id && $employee->user_id !== $by->id && ($person = User::query()->find($employee->user_id))) {
            $person->notify(new LifecycleNotification('A competency was agreed with you', $competency->name.': '.Competency::LEVELS[$level].'.', route('my-work.competencies'), teamId: $employee->team_id));
        }

        return $row;
    }

    /**
     * The person's picture: every live competency in the framework, those their position
     * expects first, then the rest by area and name.
     *
     * @return list<array{competency: Competency, expected: int|null, self: int|null, agreed: int|null, assessment: CompetencyAssessment|null, toGrow: bool}>
     */
    public function profile(Employee $employee): array
    {
        $expected = $employee->position_id
            ? PositionCompetency::query()->where('position_id', $employee->position_id)->pluck('expected_level', 'competency_id')->all()
            : [];
        $assessments = CompetencyAssessment::query()->where('employee_id', $employee->id)->get()->keyBy('competency_id');

        return Competency::query()->live()->orderBy('area')->orderBy('name')->get()
            ->sortBy(fn (Competency $c) => isset($expected[$c->id]) ? 0 : 1)
            ->map(function (Competency $c) use ($expected, $assessments) {
                $a = $assessments->get($c->id);
                $want = $expected[$c->id] ?? null;

                return [
                    'competency' => $c,
                    'expected' => $want,
                    'self' => $a?->self_level,
                    'agreed' => $a?->agreed_level,
                    'assessment' => $a,
                    'toGrow' => $want !== null && ($a?->agreed_level ?? 0) < $want,
                ];
            })->values()->all();
    }

    /**
     * Learning path steps that grow each competency, on paths still in use: a gap is shown with
     * the step that closes it.
     *
     * @return Collection<int|string, EloquentCollection<int, LearningPathStep>> keyed by competency id
     */
    public function pathSteps(): Collection
    {
        return LearningPathStep::query()->whereNull('retired_at')->whereNotNull('competency_id')
            ->whereHas('path', fn ($q) => $q->whereNull('retired_at'))->with('path')->orderBy('position')->get()->groupBy('competency_id')->toBase();
    }

    private function row(Employee $employee, Competency $competency): CompetencyAssessment
    {
        $row = CompetencyAssessment::query()->where('employee_id', $employee->id)->where('competency_id', $competency->id)->first() ?? new CompetencyAssessment;
        $row->forceFill(['team_id' => $employee->team_id, 'employee_id' => $employee->id, 'competency_id' => $competency->id]);

        return $row;
    }

    private function check(Employee $employee, Competency $competency, int $level): void
    {
        if ($competency->team_id !== $employee->team_id || $competency->retired_at !== null) {
            throw ValidationException::withMessages(['level' => 'That competency is not in use here.']);
        }
        if (! array_key_exists($level, Competency::LEVELS)) {
            throw ValidationException::withMessages(['level' => 'Choose one of the three levels.']);
        }
    }
}
