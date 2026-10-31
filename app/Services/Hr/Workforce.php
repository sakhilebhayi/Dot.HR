<?php

namespace App\Services\Hr;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Support\Hr\LocalTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The rules of time (R-32 Phase 6a), in one place so controllers stay thin:
 *
 * - A person is never put on two overlapping shifts, nor on a shift during approved leave.
 * - A person has at most one open clock-in at a time.
 * - A week's hours are built from closed attendance records; overtime is flagged only above
 *   the business's own weekly ordinary hours (nothing is flagged when it has not set them).
 * - An approved week is locked: its attendance can no longer be recorded or corrected.
 *
 * Every refusal is a RuntimeException carrying a sentence for the person, never a code.
 */
final class Workforce
{
    public function assign(Shift $shift, Employee $employee, int $actorId): void
    {
        if ($employee->team_id !== $shift->team_id) {
            throw new RuntimeException('That person is not in this business.');
        }

        if ($employee->status === 'terminated') {
            throw new RuntimeException($employee->fullName().' has left the business.');
        }

        DB::transaction(function () use ($shift, $employee) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            $clash = Shift::query()
                ->whereKeyNot($shift->id)
                ->whereHas('employees', fn ($q) => $q->whereKey($employee->id))
                ->where('starts_at', '<', $shift->ends_at)
                ->where('ends_at', '>', $shift->starts_at)
                ->first();

            if ($clash) {
                throw new RuntimeException($employee->fullName().' is already on a shift then ('.$clash->starts_at->format('D j M').', '.$clash->window().').');
            }

            $onLeave = LeaveRequest::query()
                ->where('employee_id', $employee->id)
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', $shift->ends_at->toDateString())
                ->whereDate('end_date', '>=', $shift->starts_at->toDateString())
                ->exists();

            if ($onLeave) {
                throw new RuntimeException($employee->fullName().' is on approved leave then.');
            }

            $shift->employees()->syncWithoutDetaching([$employee->id => ['team_id' => $shift->team_id]]);
        });
    }

    public function clockIn(Employee $employee, int $actorId): AttendanceRecord
    {
        return DB::transaction(function () use ($employee, $actorId) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            if (AttendanceRecord::query()->where('employee_id', $employee->id)->whereNull('clock_out_at')->exists()) {
                throw new RuntimeException('You are already clocked in.');
            }

            $this->assertWeekOpen($employee, LocalTime::now());

            // The shift the person is working now, if one was planned within two hours either side.
            $shift = Shift::query()
                ->whereHas('employees', fn ($q) => $q->whereKey($employee->id))
                ->where('starts_at', '<=', LocalTime::now()->addHours(2))
                ->where('ends_at', '>=', LocalTime::now()->subHours(2))
                ->orderBy('starts_at')
                ->first();

            $record = new AttendanceRecord;
            $record->forceFill([
                'team_id' => $employee->team_id,
                'employee_id' => $employee->id,
                'shift_id' => $shift?->id,
                'clock_in_at' => LocalTime::now(),
                'source' => 'self',
                'recorded_by' => $actorId,
            ])->save();

            return $record;
        });
    }

    public function clockOut(Employee $employee): AttendanceRecord
    {
        return DB::transaction(function () use ($employee) {
            /** @var AttendanceRecord|null $open */
            $open = AttendanceRecord::query()->where('employee_id', $employee->id)->whereNull('clock_out_at')->lockForUpdate()->first();

            if (! $open) {
                throw new RuntimeException('You are not clocked in.');
            }

            // A planned shift's unpaid break applies once the time worked is longer than it.
            $break = 0;
            if ($open->shift_id && ($shift = Shift::query()->find($open->shift_id))) {
                $span = (int) $open->clock_in_at->diffInMinutes(LocalTime::now());
                $break = $span > $shift->break_minutes * 2 ? $shift->break_minutes : 0;
            }

            $open->forceFill(['clock_out_at' => LocalTime::now(), 'break_minutes' => $break])->save();

            return $open;
        });
    }

    /**
     * A supervisor records a missed stretch or corrects one. Marked as theirs, audited by
     * field name, and refused in a locked week.
     *
     * @param  array{clock_in_at: string, clock_out_at: string, break_minutes?: int|null, note?: string|null}  $data
     */
    public function record(Employee $employee, array $data, int $actorId, ?AttendanceRecord $existing = null): AttendanceRecord
    {
        $in = Carbon::parse($data['clock_in_at']);
        $out = Carbon::parse($data['clock_out_at']);

        if (! $out->greaterThan($in)) {
            throw new RuntimeException('The end must be after the start.');
        }

        if ($in->diffInHours($out) > 24) {
            throw new RuntimeException('One stretch cannot be longer than 24 hours.');
        }

        $this->assertWeekOpen($employee, $in);
        if ($existing) {
            $this->assertWeekOpen($employee, $existing->clock_in_at);
        }

        $record = $existing ?? new AttendanceRecord;
        $record->forceFill([
            'team_id' => $employee->team_id,
            'employee_id' => $employee->id,
            'clock_in_at' => $in,
            'clock_out_at' => $out,
            'break_minutes' => (int) ($data['break_minutes'] ?? 0),
            'note' => $data['note'] ?? null,
            'source' => 'supervisor',
            'recorded_by' => $actorId,
        ])->save();

        Audit::record($existing ? 'attendance.corrected' : 'attendance.recorded', $record, ['clock_in_at', 'clock_out_at', 'break_minutes'], [], $employee->team_id);

        return $record;
    }

    /** @return array{worked: int, overtime: int, open: bool} minutes for the week starting $weekStart */
    public function week(Employee $employee, Carbon $weekStart): array
    {
        $records = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->where('clock_in_at', '>=', $weekStart->copy()->startOfDay())
            ->where('clock_in_at', '<', $weekStart->copy()->addWeek()->startOfDay())
            ->get();

        $worked = (int) $records->sum(fn (AttendanceRecord $r) => $r->workedMinutes());
        $ordinary = $employee->team->weekly_ordinary_hours;

        return [
            'worked' => $worked,
            'overtime' => $ordinary ? max(0, $worked - $ordinary * 60) : 0,
            'open' => $records->contains(fn (AttendanceRecord $r) => $r->clock_out_at === null),
        ];
    }

    public function submit(Employee $employee, Carbon $weekStart): Timesheet
    {
        $weekStart = $weekStart->copy()->startOfWeek();

        if ($weekStart->isAfter(LocalTime::today())) {
            throw new RuntimeException('That week has not started yet.');
        }

        return DB::transaction(function () use ($employee, $weekStart) {
            /** @var Timesheet|null $sheet */
            $sheet = Timesheet::query()->where('employee_id', $employee->id)->whereDate('week_start', $weekStart)->lockForUpdate()->first();

            if ($sheet && in_array($sheet->status, ['submitted', 'approved'], true)) {
                throw new RuntimeException($sheet->status === 'approved' ? 'That week is already approved.' : 'That week is already waiting for approval.');
            }

            $totals = $this->week($employee, $weekStart);
            if ($totals['open']) {
                throw new RuntimeException('Clock out first — a timesheet cannot include an open stretch.');
            }

            $sheet ??= new Timesheet;
            $sheet->forceFill([
                'team_id' => $employee->team_id,
                'employee_id' => $employee->id,
                'week_start' => $weekStart->toDateString(),
                'status' => 'submitted',
                'worked_minutes' => $totals['worked'],
                'overtime_minutes' => $totals['overtime'],
                'submitted_at' => now(),
                'decided_by' => null,
                'decided_at' => null,
                'decision_note' => null,
            ])->save();

            return $sheet;
        });
    }

    public function decide(Timesheet $sheet, bool $approve, ?string $note, int $actorId): void
    {
        if ($sheet->status !== 'submitted') {
            throw new RuntimeException('Only a submitted timesheet can be decided.');
        }

        $sheet->forceFill([
            'status' => $approve ? 'approved' : 'rejected',
            'decided_by' => $actorId,
            'decided_at' => now(),
            'decision_note' => $note,
        ])->save();

        Audit::record($approve ? 'timesheet.approved' : 'timesheet.rejected', $sheet, ['status'], ['worked_minutes' => $sheet->worked_minutes], $sheet->team_id);
    }

    private function assertWeekOpen(Employee $employee, Carbon $moment): void
    {
        $locked = Timesheet::query()
            ->where('employee_id', $employee->id)
            ->whereDate('week_start', $moment->copy()->startOfWeek()->toDateString())
            ->where('status', 'approved')
            ->exists();

        if ($locked) {
            throw new RuntimeException('That week\'s timesheet is approved, so its hours are locked.');
        }
    }
}
