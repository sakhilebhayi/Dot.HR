<?php

namespace App\Services\Intelligence;

use App\Models\Application;
use App\Models\ContractorInvoice;
use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Models\Engagement;
use App\Models\LeaveRequest;
use App\Models\PositionRequirement;
use App\Models\TrainingRecord;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\Hr\LeaveCalendar;
use App\Services\Recruitment\SkillMatch;
use App\Support\Hr\LocalTime;
use Illuminate\Support\Carbon;

/**
 * The business's own numbers (R-32 Phase 7, brief §13 "business intelligence"), each computed
 * from records in Dot.HR and nothing else. Where there is no data the figure is null and the
 * page says so; nothing is estimated, extrapolated or invented. Every figure states how it is
 * worked out, next to it on the page.
 */
final class Insights
{
    public function __construct(private SkillsEvidence $evidence, private InternalTalent $talent, private LeaveCalendar $calendar) {}

    /** @return array{active: int, byType: array<string, int>, joiners: int, leavers: int, averageHeadcount: float, turnover: float|null} */
    public function workforce(): array
    {
        $today = LocalTime::today();
        $yearAgo = $today->copy()->subYear();

        $active = Employee::query()->where('status', '!=', 'terminated')->get(['employment_type']);
        $then = Employee::query()
            ->where(fn ($q) => $q->whereNull('start_date')->orWhereDate('start_date', '<=', $yearAgo))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>', $yearAgo))
            ->count();

        $leavers = Employee::query()->where('status', 'terminated')
            ->whereDate('end_date', '>', $yearAgo)->whereDate('end_date', '<=', $today)->count();
        $joiners = Employee::query()->whereDate('start_date', '>', $yearAgo)->whereDate('start_date', '<=', $today)->count();

        $average = ($active->count() + $then) / 2;

        return [
            'active' => $active->count(),
            'byType' => $active->countBy('employment_type')->all(),
            'joiners' => $joiners,
            'leavers' => $leavers,
            'averageHeadcount' => $average,
            'turnover' => $average >= 1 ? round($leavers / $average * 100, 1) : null,
        ];
    }

    /**
     * @return array{open: int, applications90: int, hires: int, medianDaysToHire: int|null, stages: array<string, int>, vacancies: list<array{vacancy: Vacancy, days: int|null, applications: int, progressed: int, hired: int}>}
     */
    public function recruitment(): array
    {
        $now = now();
        $yearAgo = $now->copy()->subYear();

        $hired = Application::query()->where('stage', 'hired')->where('stage_changed_at', '>=', $yearAgo)->get(['created_at', 'stage_changed_at']);
        $days = $hired->map(fn (Application $a) => (int) $a->created_at->diffInDays($a->stage_changed_at ?? $a->created_at))->sort()->values();

        $vacancies = Vacancy::query()
            ->where(fn ($q) => $q->whereIn('status', ['published', 'paused'])->orWhere('updated_at', '>=', $yearAgo))
            ->whereNotNull('published_at')
            ->withCount([
                'applications',
                'applications as progressed_count' => fn ($q) => $q->whereIn('stage', ['shortlisted', 'interview', 'offer', 'hired']),
                'applications as hired_count' => fn ($q) => $q->where('stage', 'hired'),
            ])
            ->orderByRaw("case status when 'published' then 0 when 'paused' then 1 else 2 end")->latest('published_at')
            ->limit(12)->get();

        return [
            'open' => Vacancy::query()->where('status', 'published')->count(),
            'applications90' => Application::query()->where('created_at', '>=', $now->copy()->subDays(90))->count(),
            'hires' => $hired->count(),
            'medianDaysToHire' => $days->isEmpty() ? null : (int) $days[intdiv($days->count(), 2)],
            'stages' => Application::query()->where('created_at', '>=', $yearAgo)->get(['stage'])->countBy('stage')->all(),
            'vacancies' => $vacancies->map(fn (Vacancy $v) => [
                'vacancy' => $v,
                'days' => $v->published_at ? (int) $v->published_at->diffInDays($v->status === 'closed' ? $v->updated_at : $now) : null,
                'applications' => (int) $v->getAttribute('applications_count'),
                'progressed' => (int) $v->getAttribute('progressed_count'),
                'hired' => (int) $v->getAttribute('hired_count'),
            ])->values()->all(),
        ];
    }

    /**
     * Approved leave, in working days, for each of the last six months and by type.
     *
     * @return array{months: array<string, float>, byType: array<string, float>}
     */
    public function leave(): array
    {
        $start = LocalTime::today()->startOfMonth()->subMonths(5);
        $end = LocalTime::today()->endOfMonth();

        $months = [];
        for ($m = $start->copy(); $m->lte($end); $m->addMonth()) {
            $months[$m->format('M Y')] = 0.0;
        }

        $byType = [];
        $requests = LeaveRequest::query()->where('status', 'approved')
            ->whereDate('end_date', '>=', $start)->whereDate('start_date', '<=', $end)
            ->with('leaveType')->get();

        foreach ($requests as $request) {
            foreach (array_keys($months) as $label) {
                $monthStart = Carbon::createFromFormat('M Y', $label)->startOfMonth();
                $daysIn = $this->calendar->workingDaysWithin($request->start_date, $request->end_date, $monthStart, $monthStart->copy()->endOfMonth(), $request->half_day);
                $months[$label] += $daysIn;
                if ($daysIn > 0) {
                    $byType[$request->typeLabel()] = ($byType[$request->typeLabel()] ?? 0.0) + $daysIn;
                }
            }
        }

        arsort($byType);

        return ['months' => $months, 'byType' => $byType];
    }

    /** @return array{completed: int, hours: float, planned: int, requested: int, expired: int, lapsing: int} */
    public function development(): array
    {
        $yearAgo = LocalTime::today()->subYear();
        $completed = TrainingRecord::query()->where('status', 'completed')->whereDate('completed_on', '>', $yearAgo)->get(['hours']);
        $today = LocalTime::today();

        return [
            'completed' => $completed->count(),
            'hours' => (float) $completed->sum(fn (TrainingRecord $t) => (float) $t->hours),
            'planned' => TrainingRecord::query()->where('status', 'planned')->count(),
            'requested' => TrainingRecord::query()->where('status', 'requested')->count(),
            'expired' => EmployeeCertification::query()->whereNotNull('expires_on')->whereDate('expires_on', '<', $today)->count(),
            'lapsing' => EmployeeCertification::query()->whereDate('expires_on', '>=', $today)
                ->whereDate('expires_on', '<=', $today->copy()->addDays(EmployeeCertification::SOON_DAYS))->count(),
        ];
    }

    /** @return array{active: int, spend: array<string, float>, disputed: int} */
    public function contractors(): array
    {
        $yearAgo = LocalTime::today()->subYear();
        $spend = [];

        ContractorInvoice::query()->whereIn('status', ['approved', 'paid'])->whereDate('received_on', '>', $yearAgo)
            ->with('engagement:id,currency')->get()
            ->each(function (ContractorInvoice $invoice) use (&$spend) {
                $currency = $invoice->engagement->currency;
                $spend[$currency] = ($spend[$currency] ?? 0.0) + (float) $invoice->amount;
            });

        return [
            'active' => Engagement::query()->where('status', 'active')->count(),
            'spend' => $spend,
            'disputed' => ContractorInvoice::query()->where('status', 'disputed')->count(),
        ];
    }

    /**
     * The skills the business needs right now — what open roles ask for and what positions
     * require — and who in the business holds each, with the source of the evidence.
     *
     * @return list<array{skill: string, needed_by: list<string>, holders: list<array{name: string, source: string}>}>
     */
    public function coverage(User $viewer): array
    {
        $needed = [];

        foreach (Vacancy::query()->where('status', 'published')->get(['title', 'skills']) as $vacancy) {
            foreach ($vacancy->skills ?? [] as $skill) {
                $key = SkillMatch::normalise((string) $skill);
                if ($key === '') {
                    continue;
                }
                $needed[$key] ??= ['skill' => (string) $skill, 'needed_by' => []];
                $needed[$key]['needed_by'][] = 'the '.$vacancy->title.' vacancy';
            }
        }

        foreach (PositionRequirement::query()->with('position:id,title')->get() as $requirement) {
            $key = SkillMatch::normalise($requirement->name);
            $needed[$key] ??= ['skill' => $requirement->name, 'needed_by' => []];
            $needed[$key]['needed_by'][] = $requirement->position->title.' positions';
        }

        if ($needed === []) {
            return [];
        }

        $people = Employee::query()->where('status', '!=', 'terminated')->orderBy('first_name')->get();
        $evidence = $this->evidence->forMany($people, $viewer);

        $rows = [];
        foreach ($needed as $key => $row) {
            $holders = [];
            foreach ($people as $person) {
                if (isset($evidence[$person->id][$key])) {
                    $holders[] = ['name' => $person->fullName(), 'source' => $evidence[$person->id][$key]['source']];
                }
            }
            $rows[] = ['skill' => $row['skill'], 'needed_by' => array_values(array_unique($row['needed_by'])), 'holders' => $holders];
        }

        // Fewest holders first: the skills the business is most exposed on lead the list.
        usort($rows, fn ($a, $b) => count($a['holders']) <=> count($b['holders']) ?: strcmp($a['skill'], $b['skill']));

        return $rows;
    }

    /**
     * Training that would let someone already here fill an open role: people who hold at least
     * half of what a role asks for, and the skills they are missing.
     *
     * @return list<array{employee: Employee, vacancy: Vacancy, missing: list<string>, matched: int, total: int}>
     */
    public function trainingSuggestions(User $viewer): array
    {
        $suggestions = [];

        foreach (Vacancy::query()->where('status', 'published')->get() as $vacancy) {
            foreach ($this->talent->forVacancy($vacancy, $viewer) as $row) {
                if ($row['missing'] !== [] && count($row['matched']) * 2 >= $row['total']) {
                    $suggestions[] = [
                        'employee' => $row['employee'],
                        'vacancy' => $vacancy,
                        'missing' => $row['missing'],
                        'matched' => count($row['matched']),
                        'total' => $row['total'],
                    ];
                }
            }
        }

        return array_slice($suggestions, 0, 12);
    }
}
