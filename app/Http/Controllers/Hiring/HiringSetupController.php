<?php

namespace App\Http\Controllers\Hiring;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\HiringCost;
use App\Models\HiringStep;
use App\Models\Vacancy;
use App\Services\Hr\Audit;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The business's own hiring steps inside the fixed stages, and the costs of filling a role
 * (brief §5). Recruiters (and HR administrators) only.
 */
class HiringSetupController extends Controller
{
    public function steps(Request $request): View
    {
        $this->recruiter($request);

        return view('hiring.steps', [
            'steps' => HiringStep::query()->live()->get()->groupBy('stage'),
            'inUse' => Application::query()->whereNotNull('hiring_step_id')->whereIn('stage', Application::OPEN_STAGES)
                ->selectRaw('hiring_step_id, count(*) as n')->groupBy('hiring_step_id')->pluck('n', 'hiring_step_id'),
        ]);
    }

    public function storeStep(Request $request): RedirectResponse
    {
        $access = $this->recruiter($request);
        $data = $request->validate(['stage' => ['required', Rule::in(HiringStep::STAGES)], 'name' => ['required', 'string', 'max:60']]);

        $step = new HiringStep(['stage' => $data['stage'], 'name' => trim($data['name']),
            'position' => (int) HiringStep::query()->live()->where('stage', $data['stage'])->max('position') + 1]);
        $step->forceFill(['team_id' => $access->team()->id])->save();
        Audit::record('hiring.step_added', $step, ['stage', 'name'], [], $access->team()->id);

        return back()->with('status', '"'.$step->name.'" added under '.Application::STAGES[$step->stage].'.');
    }

    public function moveStep(Request $request, HiringStep $step, string $direction): RedirectResponse
    {
        $this->recruiter($request);
        $siblings = HiringStep::query()->live()->where('stage', $step->stage)->get()->values();
        $i = $siblings->search(fn (HiringStep $s) => $s->id === $step->id);
        $j = $direction === 'up' ? $i - 1 : $i + 1;

        if ($i !== false && isset($siblings[$j])) {
            DB::transaction(function () use ($siblings, $i, $j) {
                $order = $siblings->all();
                [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
                foreach ($order as $position => $s) {
                    $s->forceFill(['position' => $position])->save();
                }
            });
        }

        return back();
    }

    /** Retire a step. Applications on it stay in their stage and simply lose the step. */
    public function retireStep(Request $request, HiringStep $step): RedirectResponse
    {
        $this->recruiter($request);
        DB::transaction(function () use ($step) {
            $step->forceFill(['retired_at' => now()])->save();
            Application::query()->where('hiring_step_id', $step->id)->update(['hiring_step_id' => null]);
        });
        Audit::record('hiring.step_retired', $step, ['retired_at'], [], $step->team_id);

        return back()->with('status', '"'.$step->name.'" retired. Applications on it stay where they are.');
    }

    public function storeCost(Request $request, Vacancy $vacancy): RedirectResponse
    {
        $this->recruiter($request);
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(HiringCost::KINDS))],
            'description' => ['nullable', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'incurred_on' => ['required', 'date'],
        ]);

        $cost = new HiringCost([...$data, 'currency' => strtoupper($data['currency'])]);
        $cost->forceFill(['team_id' => $vacancy->team_id, 'vacancy_id' => $vacancy->id, 'created_by' => $request->user()->id])->save();
        Audit::record('hiring.cost_recorded', $cost, ['kind', 'amount', 'incurred_on'], [], $vacancy->team_id);

        return redirect()->to(route('hiring.show', $vacancy).'#costs')->with('status', HiringCost::KINDS[$cost->kind].' cost recorded.');
    }

    public function destroyCost(Request $request, HiringCost $cost): RedirectResponse
    {
        $this->recruiter($request);
        Audit::record('hiring.cost_removed', $cost, ['amount'], [], $cost->team_id);
        $vacancy = $cost->vacancy;
        $cost->delete();

        return redirect()->to(route('hiring.show', $vacancy).'#costs')->with('status', 'Cost removed.');
    }

    private function recruiter(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->canRecruit(), 403);

        return $access;
    }
}
