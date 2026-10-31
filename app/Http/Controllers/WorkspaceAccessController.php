<?php

namespace App\Http\Controllers;

use App\Models\HrGrant;
use App\Services\Hr\Audit;
use App\Support\Hr\HrAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Who in this business may recruit (R-32 Phase 3). InfoDot decides who belongs to the
 * company; an HR administrator here decides who among them may publish roles and
 * handle candidates. Granting and revoking are both audited.
 */
class WorkspaceAccessController extends Controller
{
    public function index(Request $request): View
    {
        $access = $this->adminAccess($request);
        $team = $access->team();

        $grants = HrGrant::query()->where('team_id', $team->id)->get()->groupBy('user_id');

        $members = $team->allUsers()->sortBy('name')->values()->map(fn ($member) => [
            'user' => $member,
            'role' => $team->user_id === $member->id ? 'Owner' : ($member->teamRole($team)->name ?? 'Member'),
            'isAdmin' => $team->user_id === $member->id || $member->hasTeamRole($team, 'admin'),
            'grants' => ($grants->get($member->id) ?? collect())->values(),
        ]);

        return view('workspace-access.index', [
            'team' => $team,
            'members' => $members,
            'capabilities' => HrGrant::CAPABILITIES,
            'infodotBusinessUrl' => $team->infodot_team_uuid
                ? rtrim((string) config('ecosystem.platforms.infodot.url'), '/').'/business/'.$team->infodot_team_uuid
                : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $access = $this->adminAccess($request);
        $team = $access->team();

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'capability' => ['required', Rule::in(array_keys(HrGrant::CAPABILITIES))],
        ]);

        // Only a current member can hold a grant: an id from outside the company is refused.
        $member = $team->allUsers()->firstWhere('id', (int) $data['user_id']);
        abort_if($member === null, 422, 'That person is not a member of this business.');

        $grant = HrGrant::query()->firstOrCreate(
            ['team_id' => $team->id, 'user_id' => $member->id, 'capability' => $data['capability']],
            ['granted_by' => $request->user()->id],
        );

        if ($grant->wasRecentlyCreated) {
            Audit::record('access.granted', $grant, ['capability'], ['capability' => $data['capability'], 'user_id' => $member->id], $team->id);
        }

        return back()->with('status', $member->name.' may now act as '.strtolower(explode(' —', HrGrant::CAPABILITIES[$data['capability']])[0]).'.');
    }

    public function destroy(Request $request, HrGrant $grant): RedirectResponse
    {
        $access = $this->adminAccess($request);
        abort_unless($grant->team_id === $access->team()->id, 404);

        Audit::record('access.revoked', $grant, ['capability'], ['capability' => $grant->capability, 'user_id' => $grant->user_id], $grant->team_id);
        $grant->delete();

        return back()->with('status', 'Access removed.');
    }

    private function adminAccess(Request $request): HrAccess
    {
        $access = HrAccess::current($request->user());
        abort_unless($access?->canManageAccess(), 403);

        return $access;
    }
}
