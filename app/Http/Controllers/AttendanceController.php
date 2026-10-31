<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\Hr\Workforce;
use App\Support\Hr\HrAccess;
use App\Support\Hr\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use RuntimeException;

/**
 * Attendance for HR administrators and supervisors (R-32 Phase 6a): who is clocked in now,
 * a day's record, and recording a missed stretch or correcting one (audited, refused in a
 * week whose timesheet is approved).
 */
class AttendanceController extends Controller
{
    public function __construct(private Workforce $workforce) {}

    public function index(Request $request): View
    {
        $this->supervisor($request);

        try {
            $day = Carbon::parse((string) $request->query('day', LocalTime::today()->toDateString()))->startOfDay();
        } catch (\Throwable) {
            $day = LocalTime::today();
        }

        return view('attendance.index', [
            'day' => $day,
            'inNow' => AttendanceRecord::query()->whereNull('clock_out_at')->with('employee')->orderBy('clock_in_at')->get(),
            'records' => AttendanceRecord::query()
                ->where('clock_in_at', '>=', $day)->where('clock_in_at', '<', $day->copy()->addDay())
                ->with('employee')->orderBy('clock_in_at')->get(),
            'employees' => Employee::query()->where('status', '!=', 'terminated')->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->supervisor($request);
        $data = $this->validated($request);
        $employee = Employee::query()->findOrFail($request->integer('employee_id'));

        try {
            $saved = $this->workforce->record($employee, $data, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('attendance.index', ['day' => $saved->clock_in_at->toDateString()])
            ->with('status', 'Recorded for '.$employee->fullName().'.');
    }

    public function update(Request $request, AttendanceRecord $record): RedirectResponse
    {
        $this->supervisor($request);
        $data = $this->validated($request, withEmployee: false);

        try {
            $saved = $this->workforce->record($record->employee, $data, $request->user()->id, $record);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('attendance.index', ['day' => $saved->clock_in_at->toDateString()])
            ->with('status', 'Corrected. The change is in the audit log.');
    }

    /** @return array{clock_in_at: string, clock_out_at: string, break_minutes: int|null, note: string|null} */
    private function validated(Request $request, bool $withEmployee = true): array
    {
        $data = $request->validate(array_filter([
            'employee_id' => $withEmployee ? ['required', 'integer'] : null,
            'clock_in_at' => ['required', 'date'],
            'clock_out_at' => ['required', 'date'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'note' => ['nullable', 'string', 'max:255'],
        ]));

        return [
            'clock_in_at' => $data['clock_in_at'],
            'clock_out_at' => $data['clock_out_at'],
            'break_minutes' => $data['break_minutes'] ?? null,
            'note' => $data['note'] ?? null,
        ];
    }

    private function supervisor(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->canManageWorkforce(), 403);

        return $access;
    }
}
