<?php

namespace Tests\Feature\Hr;

use App\Models\Employee;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Jetstream\Jetstream;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * One role matrix, enforced server-side and mirrored in the UI:
 *   owner/admin = HR admin: everyone, decides leave, manages employees.
 *   editor      = manager-lite: employee list basics and leave dates, never contact
 *                 details or leave reasons of others.
 *   linked      = "self": only their own record and leave.
 *   any other member (role viewer, unlinked): nothing.
 */
class RolesVisibilityTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    private function seedTeam(): array
    {
        $owner = $this->owner();
        $team = $owner->currentTeam;
        $editor = $this->memberOf($owner, 'editor');
        $staff = $this->memberOf($owner, 'viewer');
        $stranger = $this->memberOf($owner, 'viewer');

        $mine = $this->employeeIn($team, $staff, ['first_name' => 'Selfie', 'last_name' => 'Staffer', 'phone' => '+27 82 000 1111']);
        $other = $this->employeeIn($team, null, [
            'first_name' => 'Quorvath',
            'last_name' => 'Zelmarsh',
            'email' => 'quorvath.zelmarsh@example.test',
            'phone' => '+27 83 999 8888',
        ]);

        $myLeave = $this->leaveFor($mine, '2026-03-02', '2026-03-03', ['reason' => 'my own reason']);
        $otherLeave = $this->leaveFor($other, '2026-03-09', '2026-03-10', ['reason' => 'quorvath private reason']);

        return compact('owner', 'team', 'editor', 'staff', 'stranger', 'mine', 'other', 'myLeave', 'otherLeave');
    }

    public function test_admin_sees_every_employee_with_contact_details_and_leave_reasons(): void
    {
        ['owner' => $owner, 'other' => $other, 'otherLeave' => $otherLeave] = $this->seedTeam();

        $this->actingAs($owner)->get(route('employees.index'))->assertOk()->assertSee('Quorvath')->assertSee('Selfie');
        $this->actingAs($owner)->get(route('employees.show', $other))->assertOk()
            ->assertSee('quorvath.zelmarsh@example.test')->assertSee('+27 83 999 8888');
        $this->actingAs($owner)->get(route('leave-requests.show', $otherLeave))->assertOk()->assertSee('quorvath private reason');
    }

    public function test_editor_sees_employee_basics_but_never_contact_details(): void
    {
        ['editor' => $editor, 'other' => $other] = $this->seedTeam();

        $this->actingAs($editor)->get(route('employees.index'))->assertOk()
            ->assertSee('Quorvath')->assertSee('Selfie')
            ->assertDontSee('quorvath.zelmarsh@example.test')->assertDontSee('+27 83 999 8888');

        $this->actingAs($editor)->get(route('employees.show', $other))->assertOk()
            ->assertSee('Quorvath')
            ->assertDontSee('quorvath.zelmarsh@example.test')
            ->assertDontSee('+27 83 999 8888');
    }

    public function test_editor_cannot_search_employees_by_email_to_probe_contact_details(): void
    {
        ['editor' => $editor] = $this->seedTeam();

        $this->actingAs($editor)->get(route('employees.index', ['search' => 'quorvath.zelmarsh@example.test']))
            ->assertOk()->assertDontSee('Zelmarsh');
    }

    public function test_editor_sees_leave_dates_and_status_but_never_reasons_or_types(): void
    {
        ['editor' => $editor, 'otherLeave' => $otherLeave] = $this->seedTeam();

        $this->actingAs($editor)->get(route('leave-requests.index'))->assertOk()->assertSee('Quorvath');
        $this->actingAs($editor)->get(route('leave-requests.show', $otherLeave))->assertOk()
            ->assertSee('Quorvath')
            ->assertDontSee('quorvath private reason');
    }

    public function test_editor_cannot_decide_leave_or_manage_employees(): void
    {
        ['editor' => $editor, 'otherLeave' => $otherLeave, 'other' => $other] = $this->seedTeam();

        $this->actingAs($editor)->post(route('leave-requests.approve', $otherLeave))->assertForbidden();
        $this->actingAs($editor)->post(route('leave-requests.deny', $otherLeave))->assertForbidden();
        $this->actingAs($editor)->get(route('employees.create'))->assertForbidden();
        $this->actingAs($editor)->get(route('employees.edit', $other))->assertForbidden();
        $this->actingAs($editor)->post(route('employees.link', $other), ['user_id' => $editor->id])->assertForbidden();
    }

    public function test_linked_member_sees_only_their_own_record_and_leave(): void
    {
        ['staff' => $staff, 'mine' => $mine, 'other' => $other, 'myLeave' => $myLeave, 'otherLeave' => $otherLeave] = $this->seedTeam();

        $this->actingAs($staff)->get(route('employees.index'))->assertOk()
            ->assertSee('Selfie')->assertDontSee('Quorvath');

        $this->actingAs($staff)->get(route('employees.show', $mine))->assertOk()->assertSee('+27 82 000 1111');
        $this->actingAs($staff)->get(route('employees.show', $other))->assertForbidden();

        $this->actingAs($staff)->get(route('leave-requests.index'))->assertOk()
            ->assertSee('Selfie')->assertDontSee('Quorvath');
        $this->actingAs($staff)->get(route('leave-requests.show', $myLeave))->assertOk()->assertSee('my own reason');
        $this->actingAs($staff)->get(route('leave-requests.show', $otherLeave))->assertForbidden();
    }

    public function test_linked_member_cannot_edit_or_decide_anything_even_their_own(): void
    {
        ['staff' => $staff, 'mine' => $mine, 'myLeave' => $myLeave] = $this->seedTeam();

        $this->actingAs($staff)->put(route('employees.update', $mine), [
            'first_name' => 'Hacked', 'last_name' => 'X', 'employment_type' => 'full_time', 'status' => 'active',
        ])->assertForbidden();
        $this->actingAs($staff)->post(route('leave-requests.approve', $myLeave))->assertForbidden();
        $this->assertSame('pending', $myLeave->fresh()->status);
    }

    public function test_unlinked_plain_member_sees_nothing(): void
    {
        ['stranger' => $stranger, 'other' => $other, 'otherLeave' => $otherLeave] = $this->seedTeam();

        $this->actingAs($stranger)->get(route('employees.index'))->assertForbidden();
        $this->actingAs($stranger)->get(route('employees.show', $other))->assertForbidden();
        $this->actingAs($stranger)->get(route('leave-requests.index'))->assertForbidden();
        $this->actingAs($stranger)->get(route('leave-requests.show', $otherLeave))->assertForbidden();
        $this->actingAs($stranger)->get(route('audit-log.index'))->assertForbidden();
    }

    public function test_viewer_role_exists_so_ordinary_infodot_members_are_not_silently_editors(): void
    {
        $this->assertNotNull(Jetstream::findRole('viewer'));
    }

    public function test_buttons_a_user_cannot_use_are_not_rendered(): void
    {
        ['owner' => $owner, 'editor' => $editor, 'staff' => $staff, 'other' => $other, 'otherLeave' => $otherLeave] = $this->seedTeam();

        $this->actingAs($editor)->get(route('employees.index'))->assertDontSee('Add Employee');
        $this->actingAs($editor)->get(route('employees.show', $other))
            ->assertDontSee('>Edit<', false)->assertDontSee('>Remove<', false);
        $this->actingAs($editor)->get(route('leave-requests.show', $otherLeave))
            ->assertDontSee('>Approve<', false)->assertDontSee('>Deny<', false);

        $this->actingAs($owner)->get(route('employees.index'))->assertSee('Add Employee');
        $this->actingAs($owner)->get(route('leave-requests.show', $otherLeave))
            ->assertSee('>Approve<', false)->assertSee('>Deny<', false);

        $this->actingAs($staff)->get(route('leave-requests.index'))->assertSee('Request leave');
    }

    public function test_sidebar_hides_links_the_user_cannot_open(): void
    {
        ['editor' => $editor, 'staff' => $staff] = $this->seedTeam();

        $this->actingAs($editor)->get(route('dashboard'))->assertOk()->assertDontSee('Audit log');
        $this->actingAs($staff)->get(route('dashboard'))->assertOk()
            ->assertDontSee('Audit log')->assertDontSee('Positions');
    }

    public function test_positions_are_listed_for_managers_but_only_admins_can_change_them(): void
    {
        ['owner' => $owner, 'editor' => $editor, 'staff' => $staff, 'team' => $team] = $this->seedTeam();
        Position::factory()->create(['team_id' => $team->id, 'title' => 'Dispatcher']);

        $this->actingAs($editor)->get(route('positions.index'))->assertOk()->assertSee('Dispatcher');
        $this->actingAs($staff)->get(route('positions.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('positions.create'))->assertForbidden();
        $this->actingAs($owner)->get(route('positions.create'))->assertOk();
    }

    public function test_cross_team_isolation_still_holds_for_every_role(): void
    {
        ['staff' => $staff] = $this->seedTeam();
        $foreignOwner = $this->owner();
        $foreign = $this->employeeIn($foreignOwner->currentTeam);

        $this->actingAs($staff)->get(route('employees.show', $foreign))->assertNotFound();
        $this->assertSame(2, Employee::count(), 'only this team\'s two employees are visible to the query scope');
    }
}
