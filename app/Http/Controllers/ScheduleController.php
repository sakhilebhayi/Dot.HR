<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Shift;
use App\Services\Hr\Audit;
use App\Services\Hr\Workforce;
use App\Support\Hr\HrAccess;
use App\Support\Hr\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use RuntimeException;

/**
 * The week's schedule (R-32 Phase 6a). HR administrators and supervisors build it; managers
 * may read it. Assigning goes through Workforce::assign(), which refuses overlapping shifts
 * and shifts during approved leave with a plain reason.
 */
class ScheduleController extends Controller
{
    public function __construct(private Workforce $workforce) {}

    public function index(Request $request): View
    {
        $access = $this->reader($request);
        $week = $this->weekFrom($request->query('week'));

        $shifts = Shift::query()
            ->where('starts_at', '>=', $week)
            ->where('starts_at', '<', $week->copy()->addWeek())
            ->with('employees')
            ->orderBy('starts_at')
            ->get();

        return view('schedule.index', [
            'week' => $week,
            'days' => collect(range(0, 6))->map(fn ($i) => $week->copy()->addDays($i)),
            'shiftsByDay' => $shifts->groupBy(fn (Shift $s) => $s->starts_at->toDateString()),
            'employees' => Employee::query()->where('status', '!=', 'terminated')->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'canManage' => $access->canManageWorkforce(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $access = $this->manager($request);

        $data = $request->validate([
            'date' => ['required', 'date'],
            'start' => ['required', 'date_format:H:i'],
            'end' => ['required', 'date_format:H:i'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'title' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'employee_ids' => ['nullable', 'array', 'max:100'],
            'employee_ids.*' => ['integer'],
        ]);

        $startsAt = Carbon::parse($data['date'].' '.$data['start']);
        $endsAt = Carbon::parse($data['date'].' '.$data['end']);
        // An end at or before the start is the next morning: a night shift.
        if ($endsAt->lte($startsAt)) {
            $endsAt->addDay();
        }

        $shift = new Shift([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'break_minutes' => (int) ($data['break_minutes'] ?? 0),
            'title' => $data['title'] ?? null,
            'location' => $data['location'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
        $shift->forceFill(['team_id' => $access->team()->id, 'created_by' => $request->user()->id])->save();

        $refused = [];
        foreach ($data['employee_ids'] ?? [] as $id) {
            $employee = Employee::query()->find($id);
            if (! $employee) {
                continue;
            }
            try {
                $this->workforce->assign($shift, $employee, $request->user()->id);
            } catch (RuntimeException $e) {
                $refused[] = $e->getMessage();
            }
        }

        $redirect = redirect()->route('schedule.index', ['week' => $startsAt->copy()->startOfWeek()->toDateString()])
            ->with('status', 'Shift added for '.$startsAt->format('D j M').', '.$shift->window().'.');

        return $refused === [] ? $redirect : $redirect->with('error', 'Not assigned: '.implode(' ', $refused));
    }

    public function assign(Request $request, Shift $shift): RedirectResponse
    {
        $this->manager($request);
        $data = $request->validate(['employee_id' => ['required', 'integer']]);
        $employee = Employee::query()->findOrFail($data['employee_id']);

        try {
            $this->workforce->assign($shift, $employee, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $employee->fullName().' is on the shift.');
    }

    public function unassign(Request $request, Shift $shift, int $employee): RedirectResponse
    {
        $this->manager($request);
        $shift->employees()->detach($employee);

        return back()->with('status', 'Taken off the shift.');
    }

    public function destroy(Request $request, Shift $shift): RedirectResponse
    {
        $this->manager($request);
        Audit::record('shift.deleted', $shift, [], ['starts_at' => $shift->starts_at->toDateTimeString()], $shift->team_id);
        $shift->delete();

        return back()->with('status', 'Shift removed.');
    }

    private function weekFrom(?string $value): Carbon
    {
        try {
            $day = $value ? Carbon::parse($value) : LocalTime::today();
        } catch (\Throwable) {
            $day = LocalTime::today();
        }

        return $day->copy()->startOfWeek();
    }

    private function reader(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access && ($access->canManageWorkforce() || $access->isManager()), 403);

        return $access;
    }

    private function manager(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->canManageWorkforce(), 403);

        return $access;
    }
}
