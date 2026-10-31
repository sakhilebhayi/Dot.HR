<?php

namespace App\Services\Intelligence;

use App\Models\Application;
use App\Models\Employee;
use App\Models\InternalInvitation;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\Recruitment\SkillMatch;

/**
 * People already working for the business who hold some of what an open role asks for
 * (R-32 Phase 7, brief §10 "identify internal talent before unnecessarily recruiting
 * externally"). Each row says which skills matched and where that evidence came from, and
 * which are missing — so training can close the gap.
 *
 * It is a list to look at, not a ranking: rows are in name order and the only filter is the
 * job's own skills. Nobody is excluded for who they are, and nothing happens to anyone until
 * a person chooses to act.
 */
final class InternalTalent
{
    public function __construct(private SkillsEvidence $evidence) {}

    /**
     * @return list<array{employee: Employee, matched: list<array{name: string, source: string}>, missing: list<string>, total: int, invited: bool}>
     */
    public function forVacancy(Vacancy $vacancy, User $viewer): array
    {
        $wanted = array_values(array_filter($vacancy->skills ?? [], fn ($s) => trim((string) $s) !== ''));
        if ($wanted === []) {
            return [];
        }

        $applied = Application::query()->where('vacancy_id', $vacancy->id)->pluck('user_id')->filter()->all();
        $invited = InternalInvitation::query()->where('vacancy_id', $vacancy->id)->pluck('employee_id')->all();

        $people = Employee::query()
            ->where('status', '!=', 'terminated')
            ->where('employment_type', '!=', 'contractor')
            ->when($applied !== [], fn ($q) => $q->where(fn ($q) => $q->whereNull('user_id')->orWhereNotIn('user_id', $applied)))
            ->orderBy('first_name')->orderBy('last_name')
            ->get();

        $evidence = $this->evidence->forMany($people, $viewer);
        $rows = [];

        foreach ($people as $person) {
            $held = $evidence[$person->id] ?? [];
            $matched = [];
            $missing = [];

            foreach ($wanted as $skill) {
                $key = SkillMatch::normalise($skill);
                if (isset($held[$key])) {
                    $matched[] = ['name' => $skill, 'source' => $held[$key]['source']];
                } else {
                    $missing[] = $skill;
                }
            }

            if ($matched !== []) {
                $rows[] = [
                    'employee' => $person,
                    'matched' => $matched,
                    'missing' => $missing,
                    'total' => count($wanted),
                    'invited' => in_array($person->id, $invited, true),
                ];
            }
        }

        return $rows;
    }
}
