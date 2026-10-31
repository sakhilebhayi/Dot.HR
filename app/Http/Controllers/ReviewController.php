<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Goal;
use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\User;
use App\Services\Hr\Audit;
use App\Services\Hr\Reviews;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Written reviews and their cycles (R-32 Phase 6b, brief §10). HR administrators run cycles
 * and see every review; a reviewer sees the reviews they write; a person sees their own.
 * Nobody else — not a manager, not a colleague — can open one.
 */
class ReviewController extends Controller
{
    public function __construct(private Reviews $reviews) {}

    public function index(Request $request): View
    {
        $access = $this->member($request);

        return view('reviews.index', [
            'isAdmin' => $access->isAdmin(),
            'cycles' => $access->isAdmin()
                ? ReviewCycle::query()->withCount([
                    'reviews',
                    'reviews as done_count' => fn ($q) => $q->where('status', 'acknowledged'),
                ])->latest()->get()
                : collect(),
            'mine' => Review::query()->where('reviewer_id', $request->user()->id)->whereIn('status', ['self', 'reviewer', 'shared'])
                ->with(['employee', 'cycle'])->orderByRaw("case status when 'reviewer' then 0 when 'self' then 1 else 2 end")->get(),
        ]);
    }

    public function storeCycle(Request $request): RedirectResponse
    {
        $access = $this->admin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'closes_on' => ['nullable', 'date'],
        ]);

        $cycle = new ReviewCycle;
        $cycle->forceFill([...$data, 'team_id' => $access->team()->id, 'status' => 'open', 'created_by' => $request->user()->id])->save();
        Audit::record('review_cycle.created', $cycle, ['name', 'closes_on']);

        return redirect()->route('reviews.cycle', $cycle)->with('status', '"'.$cycle->name.'" started. Add the people it covers.');
    }

    public function cycle(Request $request, ReviewCycle $cycle): View
    {
        $access = $this->admin($request);

        return view('reviews.cycle', [
            'cycle' => $cycle,
            'reviews' => $cycle->reviews()->with(['employee', 'reviewer'])->get()->sortBy(fn (Review $r) => $r->employee->first_name),
            'people' => Employee::query()->where('status', '!=', 'terminated')->whereNotIn('id', $cycle->reviews()->pluck('employee_id'))->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'user_id']),
            'members' => $access->team()->allUsers()->sortBy('name')->values(),
        ]);
    }

    public function addPeople(Request $request, ReviewCycle $cycle): RedirectResponse
    {
        $access = $this->admin($request);
        $data = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer'],
            'reviewer_id' => ['required', 'integer'],
        ], ['employee_ids.required' => 'Tick the people this reviewer will review.']);

        $reviewer = User::query()->findOrFail($data['reviewer_id']);
        $people = Employee::query()->whereIn('id', $data['employee_ids'])->get();

        try {
            $result = $this->reviews->addPeople($cycle, $people, $reviewer, $access->team());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $back = back()->with('status', $result['added'].' '.str('review')->plural($result['added']).' added for '.$reviewer->name.'.');

        return $result['refused'] === [] ? $back : $back->with('error', 'Not added: '.implode('; ', $result['refused']).'.');
    }

    public function closeCycle(Request $request, ReviewCycle $cycle): RedirectResponse
    {
        $this->admin($request);
        $cycle->forceFill(['status' => 'closed'])->save();
        Audit::record('review_cycle.closed', $cycle, ['status']);

        return back()->with('status', '"'.$cycle->name.'" is closed. Unfinished reviews stay as they are.');
    }

    public function show(Request $request, Review $review): View
    {
        $access = $this->member($request);
        $user = $request->user();
        abort_unless($this->reviews->canRead($review, $user, $access->isAdmin()), 403);

        $isSubject = $this->reviews->isSubject($review, $user);
        $isReviewer = $review->reviewer_id === $user->id;

        // An HR administrator reading someone else's review leaves a trace.
        if (! $isSubject && ! $isReviewer) {
            Audit::record('review.viewed', $review);
        }

        return view('reviews.show', [
            'review' => $review->load(['employee.position', 'reviewer', 'cycle']),
            'goals' => Goal::query()->where('employee_id', $review->employee_id)->orderByRaw("case status when 'done' then 2 when 'dropped' then 3 else 1 end")->orderBy('due_on')->get(),
            'isSubject' => $isSubject,
            'isReviewer' => $isReviewer,
            'isAdmin' => $access->isAdmin(),
            'members' => $access->isAdmin() ? $access->team()->allUsers()->sortBy('name')->values() : collect(),
        ]);
    }

    public function reflect(Request $request, Review $review): RedirectResponse
    {
        $this->member($request);
        abort_unless($this->reviews->isSubject($review, $request->user()), 403);
        $data = $request->validate(['self_assessment' => ['required', 'string', 'min:20', 'max:10000']], ['self_assessment.min' => 'Write a little more — a few sentences at least.']);

        return $this->attempt(fn () => $this->reviews->reflect($review, $data['self_assessment']), 'Sent to '.$review->reviewer->name.'.');
    }

    public function skip(Request $request, Review $review): RedirectResponse
    {
        $access = $this->member($request);
        abort_unless($review->reviewer_id === $request->user()->id || $access->isAdmin(), 403);

        return $this->attempt(fn () => $this->reviews->skipReflection($review), 'Going ahead without their reflection.');
    }

    public function share(Request $request, Review $review): RedirectResponse
    {
        $this->member($request);
        abort_unless($review->reviewer_id === $request->user()->id, 403);
        $data = $request->validate(['feedback' => ['required', 'string', 'min:20', 'max:10000']], ['feedback.min' => 'Write a little more — a few sentences at least.']);

        return $this->attempt(fn () => $this->reviews->share($review, $data['feedback'], $request->user()), 'Shared with '.$review->employee->fullName().'.');
    }

    public function acknowledge(Request $request, Review $review): RedirectResponse
    {
        $this->member($request);
        abort_unless($this->reviews->isSubject($review, $request->user()), 403);
        $data = $request->validate(['response' => ['nullable', 'string', 'max:5000']]);

        return $this->attempt(fn () => $this->reviews->acknowledge($review, $data['response'] ?? null), 'Thank you — your review is complete.');
    }

    public function reassign(Request $request, Review $review): RedirectResponse
    {
        $access = $this->admin($request);
        $data = $request->validate(['reviewer_id' => ['required', 'integer']]);
        $reviewer = User::query()->findOrFail($data['reviewer_id']);

        return $this->attempt(fn () => $this->reviews->reassign($review, $reviewer, $access->team()), $reviewer->name.' now reviews '.$review->employee->fullName().'.');
    }

    private function attempt(callable $action, string $ok): RedirectResponse
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', $ok);
    }

    private function member(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access !== null, 403);

        return $access;
    }

    private function admin(Request $request): HrAccess
    {
        $access = $this->member($request);
        abort_unless($access->isAdmin(), 403);

        return $access;
    }
}
