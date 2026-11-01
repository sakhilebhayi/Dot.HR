<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\AttendanceRecord;
use App\Models\ExitInterview;
use App\Models\FeedbackRound;
use App\Models\HrCase;
use App\Models\ImprovementPlan;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Services\Hr\Audit;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Retention for what Phases 4 to 7 added (R-32 Phase 8, POPIA s14: personal information is
 * not kept longer than needed). Removes, permanently and in batches:
 *
 *   - applications that ended without a hire, with their notes, history, interviews and offers;
 *   - attendance records, timesheets and shifts past the time-record period;
 *   - read in-app notifications past the notification period.
 *   - closed or withdrawn employee relations cases past the case period, with their timelines,
 *     completed or cancelled improvement plans, answered or declined exit interviews and finished
 *     360 feedback rounds past the same period;
 *
 * Each run writes one audit entry with the counts — no names, no ids of people. Runs across
 * every business with no signed-in user, so it opts out of the team scope explicitly.
 */
class ApplyRetention extends Command
{
    protected $signature = 'hr:retention {--dry-run : Report what would be removed without removing it}';

    protected $description = 'Remove ended applications, old time records and old read notifications past their retention periods';

    private const BATCH = 500;

    public function handle(): int
    {
        $days = [
            'candidates' => (int) config('hr.candidate_retention_days'),
            'time' => (int) config('hr.time_record_retention_days'),
            'notifications' => (int) config('hr.notification_retention_days'),
            'cases' => (int) config('hr.case_retention_days'),
        ];

        foreach ($days as $name => $value) {
            if ($value < 30) {
                $this->error("The {$name} retention period must be at least 30 days; refusing to run.");

                return self::FAILURE;
            }
        }

        $dry = (bool) $this->option('dry-run');
        $cut = fn (int $d): Carbon => now()->subDays($d);

        $counts = Application::withoutTeamScope(fn () => [
            'applications' => $this->sweep(
                Application::query()->whereIn('stage', ['rejected', 'withdrawn'])
                    ->where(fn ($q) => $q->where('stage_changed_at', '<=', $cut($days['candidates']))
                        ->orWhere(fn ($q) => $q->whereNull('stage_changed_at')->where('updated_at', '<=', $cut($days['candidates'])))),
                $dry,
            ),
            'attendance_records' => $this->sweep(AttendanceRecord::query()->where('clock_in_at', '<=', $cut($days['time'])), $dry),
            'timesheets' => $this->sweep(Timesheet::query()->whereDate('week_start', '<=', $cut($days['time'])), $dry),
            'shifts' => $this->sweep(Shift::query()->where('ends_at', '<=', $cut($days['time'])), $dry),
            'ended_improvement_plans' => $this->sweep(ImprovementPlan::query()->whereIn('status', ['completed', 'cancelled'])->where('decided_at', '<=', $cut($days['cases'])), $dry),
            'exit_interviews' => $this->sweep(ExitInterview::query()->where(fn ($q) => $q->where('completed_at', '<=', $cut($days['cases']))->orWhere('declined_at', '<=', $cut($days['cases']))), $dry),
            'feedback_rounds' => $this->sweep(FeedbackRound::query()->whereIn('status', ['shared', 'closed'])->where('updated_at', '<=', $cut($days['cases'])), $dry),
            'closed_cases' => $this->sweep(HrCase::query()->whereIn('status', ['closed', 'withdrawn'])->where('closed_at', '<=', $cut($days['cases'])), $dry),
            'notifications' => $this->sweepTable(
                DB::table('notifications')->whereNotNull('read_at')->where('created_at', '<=', $cut($days['notifications'])),
                $dry,
            ),
        ]);

        if (! $dry && array_sum($counts) > 0) {
            Audit::asSystem(fn () => Audit::record('retention.applied', null, array_keys(array_filter($counts)), ['removed' => $counts]));
        }

        foreach ($counts as $what => $n) {
            $this->line(sprintf('%s %d %s', $dry ? 'Would remove' : 'Removed', $n, str_replace('_', ' ', $what)));
        }

        return self::SUCCESS;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function sweep(Builder $query, bool $dry): int
    {
        if ($dry) {
            return (clone $query)->count();
        }

        $removed = 0;
        do {
            $ids = (clone $query)->orderBy('id')->limit(self::BATCH)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            // Children (notes, history, interviews, offers, shift assignments) go by their foreign keys.
            $removed += (clone $query)->getModel()->newQuery()->withoutGlobalScopes()->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::BATCH);

        return $removed;
    }

    private function sweepTable(\Illuminate\Database\Query\Builder $query, bool $dry): int
    {
        if ($dry) {
            return (clone $query)->count();
        }

        $removed = 0;
        do {
            $ids = (clone $query)->orderBy('id')->limit(self::BATCH)->pluck('id');
            $removed += DB::table('notifications')->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::BATCH);

        return $removed;
    }
}
