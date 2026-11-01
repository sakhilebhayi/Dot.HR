<?php

namespace App\Services\Hr;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Resignation;
use App\Models\Timesheet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What a payroll provider needs from HR for a pay period (brief §11, payroll): who started and
 * who leaves, approved hours and overtime, and leave taken, paid and unpaid. Dot.HR holds no pay,
 * tax or ID numbers by design, so it calculates nothing: it hands over the inputs and says what
 * is still undecided. Contractors are paid by invoice and are left out.
 *
 * Counting rules, stated on the page and in the file:
 *  - a timesheet week belongs to the period its Monday falls in, and only approved weeks count;
 *  - leave counts the working days of approved leave that fall inside the period;
 *  - a leaver is someone whose end date, or agreed last day after resigning, is in the period.
 */
final class PayrollInputs
{
    public function __construct(private LeaveCalendar $calendar) {}

    /**
     * One row per person paid through payroll in the period, by name.
     *
     * @return list<array{employee: Employee, starts: CarbonImmutable|null, leaves: CarbonImmutable|null, leaving: string|null, worked: int, overtime: int, unapproved: int, paid: float, unpaid: float, untyped: float, byType: array<string, float>}>
     */
    public function rows(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $people = Employee::query()->where('employment_type', '!=', 'contractor')
            ->where(fn ($q) => $q->whereNull('start_date')->orWhereDate('start_date', '<=', $to))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $from))
            ->where(fn ($q) => $q->where('status', '!=', 'terminated')->orWhereNotNull('end_date'))
            ->with('position')->orderBy('first_name')->orderBy('last_name')->get();
        $ids = $people->pluck('id');

        $sheets = Timesheet::query()->whereIn('employee_id', $ids)->whereDate('week_start', '>=', $from)->whereDate('week_start', '<=', $to)->get()->groupBy('employee_id');
        $leave = LeaveRequest::query()->whereIn('employee_id', $ids)->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->with('leaveType')->get()->groupBy('employee_id');
        $resigning = Resignation::query()->whereIn('employee_id', $ids)->where('status', 'acknowledged')->get()
            ->map(fn (Resignation $r) => ['employee_id' => $r->employee_id, 'day' => CarbonImmutable::parse($r->agreed_last_day ?? $r->last_day)])
            ->filter(fn (array $r) => $r['day']->betweenIncluded($from, $to))->keyBy('employee_id');

        return $people->map(function (Employee $e) use ($from, $to, $sheets, $leave, $resigning) {
            $mine = $sheets->get($e->id, collect());
            $approved = $mine->where('status', 'approved');
            [$paid, $unpaid, $untyped, $byType] = $this->leave($leave->get($e->id, collect()), $from, $to);
            $start = $e->start_date ? CarbonImmutable::parse($e->start_date) : null;
            $end = $e->end_date ? CarbonImmutable::parse($e->end_date) : null;
            $leaves = $end?->betweenIncluded($from, $to) ? $end : ($resigning->has($e->id) ? $resigning->get($e->id)['day'] : null);

            return [
                'employee' => $e,
                'starts' => $start?->betweenIncluded($from, $to) ? $start : null,
                'leaves' => $leaves,
                'leaving' => $leaves === null ? null : ($end?->betweenIncluded($from, $to) ? 'End date' : 'Resigned, last day agreed'),
                'worked' => (int) $approved->sum('worked_minutes'),
                'overtime' => (int) $approved->sum('overtime_minutes'),
                'unapproved' => $mine->where('status', '!=', 'approved')->count(),
                'paid' => $paid,
                'unpaid' => $unpaid,
                'untyped' => $untyped,
                'byType' => $byType,
            ];
        })->values()->all();
    }

    public const HEADER = ['Person', 'Position', 'Department', 'Employment type', 'Starts on', 'Leaves on', 'Why leaving',
        'Approved hours', 'Approved overtime hours', 'Timesheet weeks not approved', 'Paid leave days', 'Unpaid leave days',
        'Leave days with no leave type', 'Leave by type'];

    /**
     * The rows as the file carries them: plain values, hours to two decimals, dates as ISO.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<list<string|int|float|null>>
     */
    public function table(array $rows): array
    {
        return array_map(fn (array $r) => [
            $r['employee']->fullName(), $r['employee']->position?->title, $r['employee']->position?->department,
            ['full_time' => 'Full-time', 'part_time' => 'Part-time'][$r['employee']->employment_type] ?? $r['employee']->employment_type,
            $r['starts']?->toDateString(), $r['leaves']?->toDateString(), $r['leaving'],
            round($r['worked'] / 60, 2), round($r['overtime'] / 60, 2), $r['unapproved'],
            $r['paid'], $r['unpaid'], $r['untyped'],
            implode('; ', array_map(fn ($name, $days) => $name.' '.$days, array_keys($r['byType']), $r['byType'])),
        ], $rows);
    }

    /** A fingerprint of the inputs, so a later change to a signed-off period shows. @param list<list<mixed>> $table */
    public function fingerprint(array $table): string
    {
        return hash('sha256', json_encode($table, JSON_THROW_ON_ERROR));
    }

    /**
     * What is still open for the period, so nobody sends payroll a figure that is about to change.
     *
     * @return array{timesheets: int, leave: int, clockedIn: int}
     */
    public function checks(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'timesheets' => Timesheet::query()->where('status', 'submitted')->whereDate('week_start', '>=', $from)->whereDate('week_start', '<=', $to)->count(),
            'leave' => LeaveRequest::query()->where('status', LeaveRequest::STATUS_PENDING)->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->count(),
            'clockedIn' => AttendanceRecord::query()->whereNull('clock_out_at')->where('clock_in_at', '<=', $to->endOfDay())->count(),
        ];
    }

    /**
     * @param  Collection<int, LeaveRequest>  $requests
     * @return array{0: float, 1: float, 2: float, 3: array<string, float>}
     */
    private function leave(Collection $requests, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $paid = $unpaid = $untyped = 0.0;
        $byType = [];
        foreach ($requests as $r) {
            $days = $this->calendar->workingDaysWithin($r->start_date, $r->end_date, $from, $to, $r->half_day);
            if ($days <= 0) {
                continue;
            }
            $type = $r->leaveType instanceof LeaveType ? $r->leaveType : null;
            $name = $type !== null ? $type->name : ucfirst(str_replace('_', ' ', $r->type));
            $byType[$name] = ($byType[$name] ?? 0) + $days;
            if ($type === null) {
                $untyped += $days;
            } elseif ($type->is_paid) {
                $paid += $days;
            } else {
                $unpaid += $days;
            }
        }
        ksort($byType);

        return [$paid, $unpaid, $untyped, $byType];
    }
}
