<?php

namespace App\Services\Hr;

use App\Models\TrainingBudget;
use App\Models\TrainingRecord;
use Illuminate\Support\Collection;

/**
 * What each training budget has booked and spent (brief §10). Only training the business pays
 * counts, in the budget's own currency, dated by when it was completed (spent) or when it starts
 * (booked). A department budget counts the people whose position is in that department; a
 * whole-business budget counts everyone. Nothing is estimated: training with no cost recorded
 * is counted as "no cost recorded", not as zero spend.
 */
final class TrainingBudgets
{
    /**
     * @return Collection<int, array{budget: TrainingBudget, booked: float, spent: float, left: float, uncosted: int<0, max>}>
     */
    public function overview(): Collection
    {
        $budgets = TrainingBudget::query()->orderByDesc('starts_on')->orderBy('department')->get();
        if ($budgets->isEmpty()) {
            return collect();
        }

        $records = TrainingRecord::query()->whereIn('status', ['planned', 'completed'])->where('paid_by', 'business')
            ->with('employee.position:id,department')->get();

        return $budgets->map(function (TrainingBudget $budget) use ($records) {
            $mine = $records->filter(function (TrainingRecord $r) use ($budget) {
                $on = $r->costDate();

                return $on !== null && $on->betweenIncluded($budget->starts_on, $budget->ends_on)
                    && ($budget->department === null || mb_strtolower((string) $r->employee->position?->department) === mb_strtolower($budget->department));
            });
            $costed = $mine->whereNotNull('cost')->where('currency', $budget->currency);
            $booked = (float) $costed->where('status', 'planned')->sum(fn ($r) => (float) $r->cost);
            $spent = (float) $costed->where('status', 'completed')->sum(fn ($r) => (float) $r->cost);

            return [
                'budget' => $budget,
                'booked' => $booked,
                'spent' => $spent,
                'left' => (float) $budget->amount - $booked - $spent,
                'uncosted' => $mine->whereNull('cost')->count(),
            ];
        })->values();
    }
}
