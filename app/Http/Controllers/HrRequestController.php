<?php

namespace App\Http\Controllers;

use App\Models\HrRequest;
use App\Models\User;
use App\Notifications\LifecycleNotification;
use App\Services\Hr\Audit;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * HR's inbox of employee requests (R-32 Phase 5). HR administrators only: a manager never
 * reads what an employee asked HR. Viewing a request is audited like viewing a leave reason.
 */
class HrRequestController extends Controller
{
    public function index(Request $request): View
    {
        $this->admin($request);

        return view('requests.index', [
            'requests' => HrRequest::query()->with('employee')
                ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'answered' THEN 1 ELSE 2 END")
                ->latest()->get(),
        ]);
    }

    public function show(Request $request, HrRequest $hrRequest): View
    {
        $this->admin($request);
        Audit::record('hr_request.viewed', $hrRequest, [], [], $hrRequest->team_id);

        return view('requests.show', ['hrRequest' => $hrRequest->load('employee', 'responder')]);
    }

    public function answer(Request $request, HrRequest $hrRequest): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate(['response' => ['required', 'string', 'max:5000'], 'close' => ['nullable', 'boolean']]);

        $hrRequest->forceFill([
            'response' => $data['response'],
            'status' => ($data['close'] ?? false) ? 'closed' : 'answered',
            'responded_by' => $request->user()->id,
            'responded_at' => now(),
        ])->save();

        Audit::record('hr_request.answered', $hrRequest, ['response'], ['status' => $hrRequest->status], $hrRequest->team_id);

        if ($hrRequest->requested_by && ($person = User::query()->find($hrRequest->requested_by))) {
            $person->notify(new LifecycleNotification(
                'HR answered your request',
                $hrRequest->reference().' has an answer.',
                route('my-work.requests.show', $hrRequest),
            ));
        }

        return back()->with('status', 'Answer sent.');
    }

    public function close(Request $request, HrRequest $hrRequest): RedirectResponse
    {
        $this->admin($request);
        $hrRequest->forceFill(['status' => 'closed'])->save();

        return back()->with('status', 'Request closed.');
    }

    private function admin(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->isAdmin(), 403);

        return $access;
    }
}
