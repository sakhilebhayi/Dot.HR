<?php

namespace App\Http\Controllers\Hiring;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationNote;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\Vacancy;
use App\Notifications\CandidateUpdateNotification;
use App\Services\Recruitment\SkillMatch;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Working one application (R-32 Phase 4): stages, private notes, interviews with employer-only
 * feedback, and the offer. Recruiters act; hiring managers read, note and give interview
 * feedback. The route-bound Application is team-scoped, so another business's candidate is a
 * 404 here, never a 403 that would confirm it exists.
 */
class ApplicationController extends Controller
{
    public function show(Request $request, Application $application): View
    {
        $access = $this->reviewer($request);

        $application->load(['vacancy', 'user.professionalProfile.skills', 'user.professionalProfile.entries', 'notes.author', 'events.actor', 'interviews', 'offers']);
        $profile = $application->user->professionalProfile;

        return view('hiring.application', [
            'application' => $application,
            'vacancy' => $application->vacancy,
            'profile' => $profile,
            'match' => SkillMatch::sentence($application->vacancy->skills ?? [], $profile?->skills->pluck('name') ?? []),
            'canRecruit' => $access->canRecruit(),
        ]);
    }

    public function move(Request $request, Application $application): RedirectResponse
    {
        $this->recruiter($request);

        $data = $request->validate(['stage' => ['required', Rule::in(Application::MOVABLE_TO)]]);
        abort_unless($this->movable($application), 422, 'This application is no longer open to moving.');

        $this->moveTo($application, $data['stage'], $request->user()->id);

        return back()->with('status', 'Moved to '.Application::STAGES[$data['stage']].'.');
    }

    /**
     * Move several applications for one role at once (R-32 brief §5). The same rules as one at a
     * time: only open applications not yet at an offer, only to review, shortlisted or not
     * progressing (an interview needs a time, so it is scheduled one by one). Candidates who are
     * not progressing are told, in the words given here or the standard sentence.
     */
    public function bulk(Request $request, Vacancy $vacancy): RedirectResponse
    {
        $this->recruiter($request);

        $data = $request->validate([
            'application_ids' => ['required', 'array', 'min:1', 'max:100'],
            'application_ids.*' => ['integer'],
            'stage' => ['required', Rule::in(['review', 'shortlisted', 'rejected'])],
            'message' => ['nullable', 'string', 'max:1000'],
        ], ['application_ids.required' => 'Tick the candidates to move first.']);

        $applications = $vacancy->applications()->whereIn('id', $data['application_ids'])->with('user')->get();
        $moved = 0;
        $skipped = count(array_unique($data['application_ids'])) - $applications->count();

        foreach ($applications as $application) {
            if (! $this->movable($application) || $application->stage === $data['stage']) {
                $skipped++;

                continue;
            }
            $this->moveTo($application, $data['stage'], $request->user()->id, $data['message'] ?? null, $vacancy);
            $moved++;
        }

        $status = $moved === 0 ? 'Nobody was moved.' : 'Moved '.$moved.' to '.Application::STAGES[$data['stage']].'.';
        if ($moved > 0 && $data['stage'] === 'rejected') {
            $status .= ' They have been told.';
        }
        if ($skipped > 0) {
            $status .= ' '.$skipped.' could not move (already there, at an offer, or no longer open).';
        }

        return back()->with('status', $status);
    }

    private function movable(Application $application): bool
    {
        return $application->isOpen() && $application->stage !== 'offer';
    }

    private function moveTo(Application $application, string $stage, int $actorId, ?string $message = null, ?Vacancy $vacancy = null): void
    {
        if ($application->stage === $stage) {
            return;
        }

        $from = $application->stage;
        $application->forceFill(['stage' => $stage, 'stage_changed_at' => now()])->save();
        $application->record('stage', $actorId, ['from' => $from, 'to' => $stage]);

        if ($stage === 'rejected') {
            $title = ($vacancy ?? $application->vacancy)->title;
            $application->user->notify(new CandidateUpdateNotification(
                $application->id,
                'Application update',
                $message !== null && trim($message) !== ''
                    ? trim($message)
                    : 'Your application for '.$title.' is not progressing. Thank you for applying.',
            ));
        }
    }

    public function note(Request $request, Application $application): RedirectResponse
    {
        $this->reviewer($request);

        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        $note = new ApplicationNote(['body' => $data['body']]);
        $note->forceFill([
            'team_id' => $application->team_id,
            'application_id' => $application->id,
            'author_id' => $request->user()->id,
        ])->save();

        return back()->with('status', 'Note added. Only people in your business can see it.');
    }

    public function scheduleInterview(Request $request, Application $application): RedirectResponse
    {
        $this->recruiter($request);
        abort_unless($application->isOpen(), 422);

        $data = $request->validate([
            'starts_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['required', 'integer', 'min:10', 'max:480'],
            'mode' => ['required', Rule::in(array_keys(Interview::MODES))],
            'where' => ['nullable', 'string', 'max:255'],
            'candidate_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $interview = new Interview($data);
        $interview->forceFill([
            'team_id' => $application->team_id,
            'application_id' => $application->id,
            'scheduled_by' => $request->user()->id,
            'status' => 'scheduled',
        ])->save();

        $when = $interview->starts_at->format('D j M Y, H:i');
        $application->record('interview.scheduled', $request->user()->id, ['interview_id' => $interview->id, 'when' => $when]);

        if (in_array($application->stage, ['new', 'review', 'shortlisted'], true)) {
            $from = $application->stage;
            $application->forceFill(['stage' => 'interview', 'stage_changed_at' => now()])->save();
            $application->record('stage', $request->user()->id, ['from' => $from, 'to' => 'interview']);
        }

        $application->user->notify(new CandidateUpdateNotification(
            $application->id,
            'Interview invitation',
            $application->vacancy->team->name.' invited you to an interview for '.$application->vacancy->title.' on '.$when.'.',
        ));

        return back()->with('status', 'Interview scheduled for '.$when.'. The candidate has been told.');
    }

    public function cancelInterview(Request $request, Application $application, int $interview): RedirectResponse
    {
        $this->recruiter($request);

        $row = $application->interviews()->whereKey($interview)->where('status', 'scheduled')->firstOrFail();
        $row->forceFill(['status' => 'cancelled'])->save();
        $application->record('interview.cancelled', $request->user()->id, ['interview_id' => $row->id]);

        $application->user->notify(new CandidateUpdateNotification(
            $application->id,
            'Interview cancelled',
            'Your interview for '.$application->vacancy->title.' on '.$row->starts_at->format('D j M, H:i').' was cancelled. The business will be in touch.',
        ));

        return back()->with('status', 'Interview cancelled and the candidate told.');
    }

    /** Employer-only verdict: never shown to the candidate. Hiring managers may give it. */
    public function feedback(Request $request, Application $application, int $interview): RedirectResponse
    {
        $this->reviewer($request);

        $data = $request->validate([
            'recommendation' => ['required', Rule::in(array_keys(Interview::RECOMMENDATIONS))],
            'feedback' => ['nullable', 'string', 'max:4000'],
        ]);

        $row = $application->interviews()->whereKey($interview)->where('status', '!=', 'cancelled')->firstOrFail();
        $row->forceFill([
            'recommendation' => $data['recommendation'],
            'feedback' => $data['feedback'] ?? null,
            'status' => 'completed',
        ])->save();

        $application->record('interview.feedback', $request->user()->id, ['recommendation' => Interview::RECOMMENDATIONS[$data['recommendation']]]);

        return back()->with('status', 'Feedback recorded.');
    }

    /** Draft and send in one step, or save as draft. One live offer per application. */
    public function offer(Request $request, Application $application): RedirectResponse
    {
        $this->recruiter($request);
        abort_unless($application->isOpen(), 422);

        abort_if(
            $application->offers()->whereIn('status', ['draft', 'sent'])->exists(),
            422,
            'This application already has an offer in progress. Withdraw it first.',
        );

        $data = $request->validate([
            'job_title' => ['required', 'string', 'max:140'],
            'pay_basis' => ['required', Rule::in(array_keys(Vacancy::PAY_BASES))],
            'pay_amount' => ['exclude_unless:pay_basis,salary,hourly,daily,project', 'required', 'numeric', 'min:0', 'max:9999999999'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'starts_on' => ['required', 'date', 'after_or_equal:today'],
            'terms' => ['nullable', 'string', 'max:6000'],
            'send' => ['nullable', 'boolean'],
        ]);

        $send = (bool) ($data['send'] ?? false);
        unset($data['send']);
        $data['pay_amount'] ??= null;
        $data['currency'] = strtoupper($data['currency']);

        $offer = new Offer($data);
        $offer->forceFill([
            'team_id' => $application->team_id,
            'application_id' => $application->id,
            'created_by' => $request->user()->id,
            'status' => $send ? 'sent' : 'draft',
            'sent_at' => $send ? now() : null,
        ])->save();

        $application->record('offer.drafted', $request->user()->id, ['offer_id' => $offer->id]);

        if ($send) {
            $this->markSent($application, $offer, $request->user()->id);
        }

        return back()->with('status', $send ? 'Offer sent. The candidate can accept or decline it in Dot.HR.' : 'Offer saved as a draft.');
    }

    public function sendOffer(Request $request, Application $application, int $offer): RedirectResponse
    {
        $this->recruiter($request);

        $row = $application->offers()->whereKey($offer)->where('status', 'draft')->firstOrFail();
        $row->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        $this->markSent($application, $row, $request->user()->id);

        return back()->with('status', 'Offer sent.');
    }

    public function withdrawOffer(Request $request, Application $application, int $offer): RedirectResponse
    {
        $this->recruiter($request);

        $row = $application->offers()->whereKey($offer)->whereIn('status', ['draft', 'sent'])->firstOrFail();
        $wasSent = $row->status === 'sent';
        $row->forceFill(['status' => 'withdrawn', 'responded_at' => now()])->save();
        $application->record('offer.withdrawn', $request->user()->id, ['offer_id' => $row->id]);

        if ($application->stage === 'offer') {
            $application->forceFill(['stage' => 'interview', 'stage_changed_at' => now()])->save();
            $application->record('stage', $request->user()->id, ['from' => 'offer', 'to' => 'interview']);
        }

        if ($wasSent) {
            $application->user->notify(new CandidateUpdateNotification(
                $application->id,
                'Offer withdrawn',
                'The offer for '.$application->vacancy->title.' was withdrawn by the business.',
            ));
        }

        return back()->with('status', 'Offer withdrawn.');
    }

    private function markSent(Application $application, Offer $offer, int $actorId): void
    {
        $application->record('offer.sent', $actorId, ['offer_id' => $offer->id]);

        if ($application->stage !== 'offer') {
            $from = $application->stage;
            $application->forceFill(['stage' => 'offer', 'stage_changed_at' => now()])->save();
            $application->record('stage', $actorId, ['from' => $from, 'to' => 'offer']);
        }

        $application->user->notify(new CandidateUpdateNotification(
            $application->id,
            'You have an offer',
            $application->vacancy->team->name.' offered you '.$offer->job_title.'. Open it to accept or decline.',
        ));
    }

    private function recruiter(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->canRecruit(), 403);

        return $access;
    }

    private function reviewer(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->canReviewCandidates(), 403);

        return $access;
    }
}
