<?php

namespace App\Http\Controllers;

use App\Models\ImprovementPlan;
use App\Models\ImprovementPlanCheckIn;
use App\Services\Hr\ImprovementPlans;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The person's side of an improvement plan: read it once it is shared, confirm reading it
 * (with their own response if they want), and comment on each check-in.
 */
class MyPlanController extends Controller
{
    public function __construct(private ImprovementPlans $plans) {}

    public function show(Request $request, ImprovementPlan $plan): View
    {
        $this->own($request, $plan);

        return view('my-work.plan', ['plan' => $plan->load(['manager', 'objectives', 'checkIns'])]);
    }

    public function acknowledge(Request $request, ImprovementPlan $plan): RedirectResponse
    {
        $this->own($request, $plan);
        $data = $request->validate(['confirm' => ['accepted'], 'response' => ['nullable', 'string', 'max:10000']]);
        $this->plans->acknowledge($plan, $data['response'] ?? null);

        return back()->with('status', 'Recorded that you have read the plan.'.(filled($data['response'] ?? null) ? ' Your response is on it.' : ''));
    }

    public function comment(Request $request, ImprovementPlanCheckIn $checkIn): RedirectResponse
    {
        $plan = $checkIn->plan;
        $this->own($request, $plan);
        abort_unless($plan->status === 'active', 404);
        $data = $request->validate(['comment' => ['required', 'string', 'min:2', 'max:5000']]);
        $this->plans->comment($checkIn, $data['comment']);

        return back()->with('status', 'Your comment is on the check-in.');
    }

    private function own(Request $request, ImprovementPlan $plan): void
    {
        abort_unless(HrAccess::forTeamId($request->user(), $plan->team_id)?->canSeeOwnPlan($plan), 404);
    }
}
