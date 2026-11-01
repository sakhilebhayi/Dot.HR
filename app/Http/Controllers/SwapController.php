<?php

namespace App\Http\Controllers;

use App\Models\ShiftSwap;
use App\Services\Hr\ShiftSwaps;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Shift swaps, the supervisor's side (brief §8): swaps colleagues agreed, waiting for approval,
 * and the recent record. HR administrators and supervisors only.
 */
class SwapController extends Controller
{
    public function __construct(private ShiftSwaps $swaps) {}

    public function index(Request $request): View
    {
        $access = $this->supervisor($request);
        $this->swaps->expireStale($access->team()->id);

        return view('schedule.swaps', [
            'waiting' => ShiftSwap::query()->where('status', 'accepted')->with(['shift', 'otherShift', 'from', 'to'])->oldest('accepted_at')->get(),
            'offered' => ShiftSwap::query()->where('status', 'offered')->with(['shift', 'otherShift', 'from', 'to'])->oldest()->get(),
            'recent' => ShiftSwap::query()->whereIn('status', ['approved', 'declined', 'cancelled', 'expired'])
                ->where('updated_at', '>=', now()->subDays(30))->with(['shift', 'otherShift', 'from', 'to'])->latest('updated_at')->limit(30)->get(),
        ]);
    }

    public function decide(Request $request, ShiftSwap $swap, string $decision): RedirectResponse
    {
        $this->supervisor($request);
        abort_unless(in_array($decision, ['approve', 'decline'], true), 404);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        $this->swaps->decide($swap, $decision === 'approve', $data['note'] ?? null, $request->user());

        return back()->with('status', $decision === 'approve' ? 'Approved. The schedule shows the change and both have been told.' : 'Declined. Both have been told.');
    }

    private function supervisor(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->canManageWorkforce(), 403);

        return $access;
    }
}
