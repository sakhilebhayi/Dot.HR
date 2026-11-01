<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftSwap;
use App\Services\Hr\ShiftSwaps;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Shift swaps, the person's side: offer a shift, take or turn down an offer, withdraw your own.
 * Acts on the employee record linked to the signed-in account.
 */
class MySwapController extends Controller
{
    public function __construct(private ShiftSwaps $swaps) {}

    public function offer(Request $request, Shift $shift): RedirectResponse
    {
        $me = $this->me($request);
        $data = $request->validate([
            'with' => ['nullable', 'string', 'max:40'], // "" (anyone), "e:{employee}" or "s:{shift}:{employee}"
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        [$to, $other] = $this->target((string) ($data['with'] ?? ''));
        $this->swaps->offer($me, $shift, $to, $other, $data['note'] ?? null);

        return redirect()->to(route('my-work.index').'#swaps')->with('status', $to
            ? 'Offered to '.$to->first_name.'. A supervisor approves once they accept.'
            : 'Offered to anyone here. A supervisor approves once someone takes it.');
    }

    public function accept(Request $request, ShiftSwap $swap): RedirectResponse
    {
        $this->swaps->accept($swap, $this->me($request));

        return back()->with('status', 'Accepted. It waits for a supervisor; nothing changes until they approve.');
    }

    public function decline(Request $request, ShiftSwap $swap): RedirectResponse
    {
        $this->swaps->decline($swap, $this->me($request));

        return back()->with('status', 'Declined. They have been told.');
    }

    public function cancel(Request $request, ShiftSwap $swap): RedirectResponse
    {
        $this->swaps->cancel($swap, $this->me($request));

        return back()->with('status', 'Withdrawn.');
    }

    /** @return array{0: Employee|null, 1: Shift|null} */
    private function target(string $with): array
    {
        if (preg_match('/^e:(\d+)$/', $with, $m)) {
            return [Employee::query()->findOrFail((int) $m[1]), null];
        }
        if (preg_match('/^s:(\d+):(\d+)$/', $with, $m)) {
            return [Employee::query()->findOrFail((int) $m[2]), Shift::query()->findOrFail((int) $m[1])];
        }

        return [null, null];
    }

    private function me(Request $request): Employee
    {
        $employee = HrAccess::current($request->user())?->employee();
        abort_if($employee === null, 404);

        return $employee;
    }
}
