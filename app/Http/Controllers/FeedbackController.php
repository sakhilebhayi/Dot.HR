<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\FeedbackResponse;
use App\Models\FeedbackRound;
use App\Models\User;
use App\Services\Hr\Audit;
use App\Services\Hr\Feedback360;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * 360 feedback (brief §10). Anyone in the business answers what they were asked; a person can
 * ask colleagues about themselves; HR can start a round about anyone. HR administrators read
 * answers with names; the person reads them without, once enough people answered.
 */
class FeedbackController extends Controller
{
    public function __construct(private Feedback360 $feedback) {}

    public function index(Request $request): View
    {
        $access = $this->member($request);
        $me = $access->employee();

        return view('feedback.index', [
            'asked' => FeedbackResponse::query()->where('giver_user_id', $request->user()->id)->with('round.employee')->latest()->get(),
            'mine' => $me ? FeedbackRound::query()->where('employee_id', $me->id)->with('responses')->latest()->get() : collect(),
            'all' => $access->isAdmin() ? FeedbackRound::query()->with(['employee', 'responses'])->latest()->limit(30)->get() : collect(),
            'admin' => $access->isAdmin(),
            'me' => $me,
            'employees' => $access->isAdmin() ? Employee::query()->where('status', '!=', 'terminated')->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'user_id']) : collect(),
            'members' => User::query()->whereIn('id', DB::table('team_user')->where('team_id', $access->team()->id)->pluck('user_id')->push($access->team()->user_id))
                ->whereKeyNot($request->user()->id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $access = $this->member($request);
        $data = $request->validate([
            'employee_id' => ['nullable', 'integer'],
            'givers' => ['required', 'array'], 'givers.*' => ['integer'],
            'focus' => ['nullable', 'string', 'max:500'],
            'due_on' => ['required', 'date'],
        ], ['givers.required' => 'Choose the colleagues to ask.']);

        // HR may ask about anyone; anyone else only about themselves.
        $employee = $access->isAdmin() && ! empty($data['employee_id']) ? Employee::query()->findOrFail($data['employee_id']) : $access->employee();
        abort_if($employee === null, 403);

        $givers = User::query()->whereIn('id', $data['givers'])->get();
        $round = $this->feedback->start($employee, $givers, $data['focus'] ?? null, Carbon::parse($data['due_on']), $request->user());

        return redirect()->route('feedback.rounds.show', $round)->with('status', 'Asked '.$givers->count().' people. Their names are never shown to '.($employee->user_id === $request->user()->id ? 'you' : $employee->first_name).'.');
    }

    public function round(Request $request, FeedbackRound $round): View
    {
        $access = $this->member($request);
        $subject = $access->employee()?->id === $round->employee_id;
        abort_unless($access->isAdmin() || $subject, 404);

        if ($access->isAdmin() && ! $subject) {
            Audit::record('feedback.viewed', $round, [], [], $round->team_id);
        }

        return view('feedback.round', [
            'round' => $round->load(['employee', 'responses.giver']),
            'admin' => $access->isAdmin() && ! $subject,
            'subject' => $subject,
            'answers' => $round->status === 'shared' || ($subject && $round->status === 'closed' && $round->shared_at) ? $this->feedback->forPerson($round) : [],
            'canShare' => $round->status === 'open' && ($access->isAdmin() || ($subject && $round->requested_by === $request->user()->id)),
        ]);
    }

    public function share(Request $request, FeedbackRound $round): RedirectResponse
    {
        $access = $this->member($request);
        $subject = $access->employee()?->id === $round->employee_id;
        abort_unless($access->isAdmin() || ($subject && $round->requested_by === $request->user()->id), 404);
        $this->feedback->share($round);

        return back()->with('status', 'Shared, without names.');
    }

    public function close(Request $request, FeedbackRound $round): RedirectResponse
    {
        abort_unless($this->member($request)->isAdmin(), 404);
        $this->feedback->close($round);

        return back()->with('status', 'Round closed.');
    }

    public function respond(Request $request, FeedbackResponse $response): View
    {
        $this->giver($request, $response);

        return view('feedback.respond', ['response' => $response->load('round.employee')]);
    }

    public function answer(Request $request, FeedbackResponse $response): RedirectResponse
    {
        $this->giver($request, $response);
        $data = $request->validate(collect(FeedbackResponse::QUESTIONS)->keys()->mapWithKeys(fn ($k) => [$k => ['nullable', 'string', 'max:3000']])->all());
        $this->feedback->answer($response, $data);

        return redirect()->route('feedback.index')->with('status', 'Thank you. Your words reach them without your name.');
    }

    public function decline(Request $request, FeedbackResponse $response): RedirectResponse
    {
        $this->giver($request, $response);
        $this->feedback->decline($response);

        return redirect()->route('feedback.index')->with('status', 'No problem.');
    }

    private function giver(Request $request, FeedbackResponse $response): void
    {
        abort_unless($response->giver_user_id === $request->user()->id, 404);
    }

    private function member(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_if($access === null, 403);

        return $access;
    }
}
