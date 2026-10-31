<?php

namespace Tests\Feature;

use App\Actions\Ecosystem\SyncTeamsFromInfoDot;
use App\Models\HrAuditLog;
use App\Models\HrGrant;
use App\Models\User;
use App\Support\Hr\HrAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/** R-32 Phase 3: business-level recruitment grants — membership alone never lets anyone recruit. */
class WorkspaceAccessTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    public function test_membership_alone_does_not_confer_recruiting_and_admins_always_may(): void
    {
        $owner = $this->owner();
        $member = $this->memberOf($owner, 'viewer');
        $manager = $this->memberOf($owner, 'editor');

        $this->assertTrue(HrAccess::current($owner)->canRecruit());
        $this->assertFalse(HrAccess::current($member)->canRecruit());
        $this->assertFalse(HrAccess::current($manager)->canRecruit(), 'A Manager role from InfoDot is not a recruiting grant');
    }

    public function test_an_admin_grants_and_revokes_recruiter_and_both_are_audited(): void
    {
        $owner = $this->owner();
        $member = $this->memberOf($owner, 'viewer');

        $this->actingAs($owner)
            ->post(route('access.store'), ['user_id' => $member->id, 'capability' => HrGrant::RECRUITER])
            ->assertRedirect();

        $this->assertTrue(HrAccess::current($member->fresh())->canRecruit());

        $grant = HrGrant::query()->firstOrFail();
        $this->actingAs($owner)->delete(route('access.destroy', $grant))->assertRedirect();

        $this->assertFalse(HrAccess::current($member->fresh())->canRecruit());

        $actions = HrAuditLog::withoutTeamScope(fn () => HrAuditLog::query()->pluck('action'))->all();
        $this->assertContains('access.granted', $actions);
        $this->assertContains('access.revoked', $actions);
    }

    public function test_only_admins_reach_the_access_page_or_change_grants(): void
    {
        $owner = $this->owner();
        $manager = $this->memberOf($owner, 'editor');
        $member = $this->memberOf($owner, 'viewer');

        $this->actingAs($owner)->get(route('access.index'))->assertOk()->assertSee('Who may recruit');
        $this->actingAs($manager)->get(route('access.index'))->assertForbidden();
        $this->actingAs($member)->get(route('access.index'))->assertForbidden();

        $this->actingAs($manager)
            ->post(route('access.store'), ['user_id' => $member->id, 'capability' => HrGrant::RECRUITER])
            ->assertForbidden();

        $this->assertSame(0, HrGrant::query()->count());
    }

    public function test_a_grant_cannot_be_given_to_an_outsider_or_removed_from_another_business(): void
    {
        $ownerA = $this->owner();
        $ownerB = $this->owner();
        $outsider = User::factory()->withPersonalTeam()->create();

        $this->actingAs($ownerA)
            ->post(route('access.store'), ['user_id' => $outsider->id, 'capability' => HrGrant::RECRUITER])
            ->assertStatus(422);

        $memberB = $this->memberOf($ownerB, 'viewer');
        $grantB = HrGrant::query()->create([
            'team_id' => $ownerB->currentTeam->id, 'user_id' => $memberB->id, 'capability' => HrGrant::RECRUITER,
        ]);

        // Business A's administrator cannot touch business B's grants.
        $this->actingAs($ownerA)->delete(route('access.destroy', $grantB))->assertNotFound();
        $this->assertTrue(HrGrant::query()->whereKey($grantB->id)->exists());
    }

    public function test_a_grant_left_behind_after_infodot_removes_someone_opens_nothing(): void
    {
        $owner = $this->owner();
        $owner->currentTeam->forceFill(['infodot_team_uuid' => 'team-uuid-a'])->save();
        $member = $this->memberOf($owner, 'viewer');

        HrGrant::query()->create([
            'team_id' => $owner->currentTeam->id, 'user_id' => $member->id, 'capability' => HrGrant::RECRUITER,
        ]);
        $this->assertTrue(HrAccess::current($member->fresh())->canRecruit());

        // InfoDot no longer lists the company for this person: the sync detaches them.
        app(SyncTeamsFromInfoDot::class)($member->fresh(), ['teams' => []]);

        $member = $member->fresh();
        $this->assertNull(HrAccess::forTeamId($member, $owner->currentTeam->id), 'No access object for a non-member');
        $this->assertSame([], $member->recruitingTeamIds());
    }

    public function test_the_access_link_shows_in_the_nav_for_admins_only(): void
    {
        $owner = $this->owner();
        $manager = $this->memberOf($owner, 'editor');

        $this->actingAs($owner)->get(route('dashboard'))->assertSee(route('access.index'), false);
        $this->actingAs($manager)->get(route('dashboard'))->assertDontSee(route('access.index'), false);
    }
}
