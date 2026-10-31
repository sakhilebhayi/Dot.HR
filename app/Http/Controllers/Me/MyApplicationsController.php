<?php

namespace App\Http\Controllers\Me;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Offer;
use App\Services\Recruitment\HireFromOffer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * A person's own applications (R-32 Phase 4). Applications are team-scoped records of the
 * businesses; the person reaches theirs through an explicit bypass that is always filtered
 * to their own user id. What they see is the candidate view: a plain stage, interviews
 * (time, mode, place, the note meant for them) and offers — never notes, feedback or history.
 */
class MyApplicationsController extends Controller
{
    public function index(Request $request): View
    {
        $applications = Application::withoutTeamScope(fn () => Application::query()
            ->where('user_id', $request->user()->id)
            ->with(['vacancy.team:id,name', 'interviews', 'offers'])
            ->latest()
            ->get());

        return view('me.applications', ['applications' => $applications]);
    }

    public function show(Request $request, int $application): View
    {
        $row = $this->mine($request, $application);

        return view('me.application', [
            'application' => $row,
            'interviews' => $row->interviews->where('status', '!=', 'cancelled')->values(),
            'offer' => $row->offers->whereIn('status', ['sent', 'accepted', 'declined'])->sortByDesc('id')->first(),
        ]);
    }

    public function withdraw(Request $request, int $application): RedirectResponse
    {
        $row = $this->mine($request, $application);
        abort_unless($row->isOpen(), 422, 'This application is already closed.');

        Application::withoutTeamScope(function () use ($row, $request) {
            $row->forceFill(['stage' => 'withdrawn', 'stage_changed_at' => now()])->save();
            $row->record('withdrawn', $request->user()->id);
            Offer::query()->where('application_id', $row->id)->whereIn('status', ['draft', 'sent'])
                ->update(['status' => 'withdrawn', 'responded_at' => now()]);
        });

        return redirect()->route('me.applications.show', $row->id)->with('status', 'You withdrew your application.');
    }

    public function respond(Request $request, int $application, string $decision): RedirectResponse
    {
        $row = $this->mine($request, $application);
        abort_unless(in_array($decision, ['accept', 'decline'], true), 404);

        $offer = Offer::withoutTeamScope(fn () => Offer::query()
            ->where('application_id', $row->id)
            ->latest('id')
            ->first());

        abort_if($offer === null, 404);

        if ($decision === 'accept') {
            try {
                app(HireFromOffer::class)->accept($offer, $request->user());
            } catch (RuntimeException) {
                return back()->with('error', 'This offer can no longer be accepted.');
            }

            return redirect()->route('me.applications.show', $row->id)
                ->with('status', 'Congratulations — you accepted the offer. '.$row->vacancy->team->name.' now has your employee record.');
        }

        if ($offer->status !== 'sent') {
            return back()->with('error', 'This offer can no longer be declined.');
        }

        Application::withoutTeamScope(function () use ($offer, $row, $request) {
            $offer->forceFill(['status' => 'declined', 'responded_at' => now()])->save();
            $row->record('offer.declined', $request->user()->id, ['offer_id' => $offer->id]);
        });

        return redirect()->route('me.applications.show', $row->id)->with('status', 'You declined the offer.');
    }

    private function mine(Request $request, int $id): Application
    {
        /** @var Application */
        return Application::withoutTeamScope(fn () => Application::query()
            ->where('user_id', $request->user()->id)
            ->with(['vacancy.team', 'interviews', 'offers'])
            ->findOrFail($id));
    }
}
