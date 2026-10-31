<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Models\PositionRequirement;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Who is ready for their position (R-32 Phase 6a, brief §8 "competency and licence tracking",
 * "site access readiness"): for every active employee whose position carries requirements,
 * which requirement is missing, expired or about to expire. Matching is by name,
 * case-insensitive — a requirement "Code 14 licence" is met by a certification of that name.
 */
final class Readiness
{
    /**
     * Only people with at least one gap, worst first.
     *
     * @return Collection<int, array{employee: Employee, missing: list<string>, expired: list<string>, soon: list<string>}>
     */
    public function gaps(int $teamId): Collection
    {
        $requirements = PositionRequirement::query()->where('team_id', $teamId)->get()->groupBy('position_id');

        if ($requirements->isEmpty()) {
            return collect();
        }

        $employees = Employee::query()
            ->where('team_id', $teamId)
            ->where('status', '!=', 'terminated')
            ->whereIn('position_id', $requirements->keys())
            ->get();

        $certs = EmployeeCertification::query()->whereIn('employee_id', $employees->pluck('id'))->get()->groupBy('employee_id');

        return $employees
            ->map(fn (Employee $employee) => $this->row(
                $employee,
                $requirements->get($employee->position_id, collect()),
                $certs->get($employee->id),
            ))
            ->filter(fn ($row) => $row['missing'] !== [] || $row['expired'] !== [] || $row['soon'] !== [])
            ->sortByDesc(fn ($row) => count($row['expired']) * 100 + count($row['missing']) * 10 + count($row['soon']))
            ->values();
    }

    /**
     * @param  Collection<int, PositionRequirement>  $requirements
     * @param  Collection<int, EmployeeCertification>|null  $certificates
     * @return array{employee: Employee, missing: list<string>, expired: list<string>, soon: list<string>}
     */
    private function row(Employee $employee, Collection $requirements, ?Collection $certificates): array
    {
        $held = $certificates === null
            ? collect()
            : $certificates->groupBy(fn (EmployeeCertification $c) => Str::lower(trim($c->name)));
        $missing = [];
        $expired = [];
        $soon = [];

        foreach ($requirements as $requirement) {
            $matches = $held->get(Str::lower(trim($requirement->name)));

            if ($matches === null) {
                $missing[] = $requirement->name;

                continue;
            }

            // The best certificate they hold for it: one without expiry, else the latest.
            $best = $matches->sortByDesc(fn (EmployeeCertification $c) => $c->expires_on->timestamp ?? PHP_INT_MAX)->first();
            $state = $best?->state();

            if ($state === 'expired') {
                $expired[] = $requirement->name;
            } elseif ($state === 'soon') {
                $soon[] = $requirement->name;
            }
        }

        return ['employee' => $employee, 'missing' => $missing, 'expired' => $expired, 'soon' => $soon];
    }
}
