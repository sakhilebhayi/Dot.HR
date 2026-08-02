<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dot.HR is team-scoped: every HR record carries a team_id and every policy
 * (EmployeePolicy, PositionPolicy, LeaveRequestPolicy) checks
 * $user->belongsToTeam($model->team). These tests are the highest-value
 * coverage for this platform given the data sensitivity (wiki.md §2): they
 * guard against one team viewing, editing, or deleting another team's
 * employee and leave-request records by guessing/incrementing a
 * route-model-bound id — the same class of gap found and fixed in
 * Dot.Tasks and Dot.Finance earlier this session, and the reason this
 * platform ships Policies from the first commit rather than adding them
 * later. Mirrors Dot.Finance's FinanceAuthorizationTest.php pattern,
 * adapted for team-scoping instead of user-scoping, and copies
 * Dot.Billing's TestCase.php bootstrap (withoutVite()).
 *
 * The role-gating block below (test_editor_team_member_*, test_admin_team_
 * member_*) covers the fix for wiki.md §9's top roadmap item: EmployeePolicy/
 * LeaveRequestPolicy/PositionPolicy's create/update/delete are now gated on
 * the team's `admin` role (or ownership), not just team membership. These
 * tests assert a plain `editor` team member gets 403 on mutations but 200 on
 * view, while an `admin` team member (and the owner) succeed.
 */
class HrAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeamOwner(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    /**
     * Create a user who is a member (not owner) of $owner's team, holding
     * the given Jetstream team role ('admin' or 'editor'), with that team
     * set as their current team so route-model-bound "current team" lookups
     * (e.g. Employee::create()/create ability checks) resolve correctly.
     */
    private function makeTeamMember(User $owner, string $role): User
    {
        $member = User::factory()->create();

        $owner->currentTeam->users()->attach($member, ['role' => $role]);

        $member->forceFill(['current_team_id' => $owner->currentTeam->id])->save();

        return $member->fresh();
    }

    public function test_user_cannot_view_another_teams_employee(): void
    {
        $owner = $this->makeTeamOwner();
        $outsider = $this->makeTeamOwner();

        $employee = Employee::factory()->create([
            'team_id' => $owner->currentTeam->id,
        ]);

        $this->actingAs($outsider)
            ->get(route('employees.show', $employee))
            ->assertForbidden();
    }

    public function test_user_cannot_update_another_teams_employee(): void
    {
        $owner = $this->makeTeamOwner();
        $outsider = $this->makeTeamOwner();

        $employee = Employee::factory()->create([
            'team_id' => $owner->currentTeam->id,
            'first_name' => 'Original',
        ]);

        $this->actingAs($outsider)
            ->put(route('employees.update', $employee), [
                'first_name' => 'Tampered',
                'last_name' => $employee->last_name,
                'employment_type' => $employee->employment_type,
                'status' => $employee->status,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'first_name' => 'Original',
        ]);
    }

    public function test_user_cannot_delete_another_teams_employee(): void
    {
        $owner = $this->makeTeamOwner();
        $outsider = $this->makeTeamOwner();

        $employee = Employee::factory()->create([
            'team_id' => $owner->currentTeam->id,
        ]);

        $this->actingAs($outsider)
            ->delete(route('employees.destroy', $employee))
            ->assertForbidden();

        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
    }

    public function test_employees_index_never_leaks_another_teams_rows(): void
    {
        $owner = $this->makeTeamOwner();
        $viewer = $this->makeTeamOwner();

        Employee::factory()->create([
            'team_id' => $owner->currentTeam->id,
            'first_name' => 'Owner-Secret',
            'last_name' => 'Employee',
        ]);
        Employee::factory()->create([
            'team_id' => $viewer->currentTeam->id,
            'first_name' => 'Viewer-Own',
            'last_name' => 'Employee',
        ]);

        $this->actingAs($viewer)
            ->get(route('employees.index'))
            ->assertOk()
            ->assertSee('Viewer-Own')
            ->assertDontSee('Owner-Secret');
    }

    public function test_user_cannot_view_another_teams_leave_request(): void
    {
        $owner = $this->makeTeamOwner();
        $outsider = $this->makeTeamOwner();

        $employee = Employee::factory()->create(['team_id' => $owner->currentTeam->id]);
        $leaveRequest = LeaveRequest::factory()->create([
            'team_id' => $owner->currentTeam->id,
            'employee_id' => $employee->id,
            'requested_by' => $owner->id,
            'reason' => 'Private medical detail',
        ]);

        $this->actingAs($outsider)
            ->get(route('leave-requests.show', $leaveRequest))
            ->assertForbidden();
    }

    public function test_user_cannot_approve_another_teams_leave_request(): void
    {
        $owner = $this->makeTeamOwner();
        $outsider = $this->makeTeamOwner();

        $employee = Employee::factory()->create(['team_id' => $owner->currentTeam->id]);
        $leaveRequest = LeaveRequest::factory()->create([
            'team_id' => $owner->currentTeam->id,
            'employee_id' => $employee->id,
            'requested_by' => $owner->id,
        ]);

        $this->actingAs($outsider)
            ->post(route('leave-requests.approve', $leaveRequest))
            ->assertForbidden();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'pending',
        ]);
    }

    public function test_user_cannot_delete_another_teams_leave_request(): void
    {
        $owner = $this->makeTeamOwner();
        $outsider = $this->makeTeamOwner();

        $employee = Employee::factory()->create(['team_id' => $owner->currentTeam->id]);
        $leaveRequest = LeaveRequest::factory()->create([
            'team_id' => $owner->currentTeam->id,
            'employee_id' => $employee->id,
            'requested_by' => $owner->id,
        ]);

        $this->actingAs($outsider)
            ->delete(route('leave-requests.destroy', $leaveRequest))
            ->assertForbidden();

        $this->assertDatabaseHas('leave_requests', ['id' => $leaveRequest->id]);
    }

    public function test_leave_requests_index_never_leaks_another_teams_rows(): void
    {
        $owner = $this->makeTeamOwner();
        $viewer = $this->makeTeamOwner();

        $ownerEmployee = Employee::factory()->create([
            'team_id' => $owner->currentTeam->id,
            'first_name' => 'Owner-Secret',
        ]);
        $viewerEmployee = Employee::factory()->create([
            'team_id' => $viewer->currentTeam->id,
            'first_name' => 'Viewer-Own',
        ]);

        LeaveRequest::factory()->create([
            'team_id' => $owner->currentTeam->id,
            'employee_id' => $ownerEmployee->id,
            'requested_by' => $owner->id,
        ]);
        LeaveRequest::factory()->create([
            'team_id' => $viewer->currentTeam->id,
            'employee_id' => $viewerEmployee->id,
            'requested_by' => $viewer->id,
        ]);

        $this->actingAs($viewer)
            ->get(route('leave-requests.index'))
            ->assertOk()
            ->assertSee('Viewer-Own')
            ->assertDontSee('Owner-Secret');
    }

    public function test_user_cannot_view_another_teams_position(): void
    {
        $owner = $this->makeTeamOwner();
        $outsider = $this->makeTeamOwner();

        $position = Position::factory()->create(['team_id' => $owner->currentTeam->id]);

        $this->assertFalse($outsider->can('view', $position));
        $this->assertTrue($owner->can('view', $position));
    }

    // ------------------------------------------------------------------
    // Role-gating: editor (non-admin) team members cannot mutate Employee
    // records, but can still view them. Admins and the team owner can.
    // ------------------------------------------------------------------

    public function test_editor_team_member_cannot_create_employee(): void
    {
        $owner = $this->makeTeamOwner();
        $editor = $this->makeTeamMember($owner, 'editor');

        $this->actingAs($editor)
            ->post(route('employees.store'), [
                'first_name' => 'New',
                'last_name' => 'Hire',
                'employment_type' => 'full_time',
                'status' => 'active',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('employees', ['first_name' => 'New', 'last_name' => 'Hire']);
    }

    public function test_editor_team_member_cannot_update_employee(): void
    {
        $owner = $this->makeTeamOwner();
        $editor = $this->makeTeamMember($owner, 'editor');

        $employee = Employee::factory()->create([
            'team_id' => $owner->currentTeam->id,
            'first_name' => 'Original',
        ]);

        $this->actingAs($editor)
            ->put(route('employees.update', $employee), [
                'first_name' => 'Tampered',
                'last_name' => $employee->last_name,
                'employment_type' => $employee->employment_type,
                'status' => $employee->status,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'first_name' => 'Original']);
    }

    public function test_editor_team_member_cannot_delete_employee(): void
    {
        $owner = $this->makeTeamOwner();
        $editor = $this->makeTeamMember($owner, 'editor');

        $employee = Employee::factory()->create(['team_id' => $owner->currentTeam->id]);

        $this->actingAs($editor)
            ->delete(route('employees.destroy', $employee))
            ->assertForbidden();

        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
    }

    public function test_editor_team_member_can_still_view_employee(): void
    {
        $owner = $this->makeTeamOwner();
        $editor = $this->makeTeamMember($owner, 'editor');

        $employee = Employee::factory()->create(['team_id' => $owner->currentTeam->id]);

        $this->actingAs($editor)
            ->get(route('employees.show', $employee))
            ->assertOk();
    }

    public function test_admin_team_member_can_create_update_and_delete_employee(): void
    {
        $owner = $this->makeTeamOwner();
        $admin = $this->makeTeamMember($owner, 'admin');

        $this->actingAs($admin)
            ->post(route('employees.store'), [
                'first_name' => 'New',
                'last_name' => 'Hire',
                'employment_type' => 'full_time',
                'status' => 'active',
            ])
            ->assertRedirect();

        $employee = Employee::where('first_name', 'New')->where('last_name', 'Hire')->firstOrFail();
        $this->assertEquals($owner->currentTeam->id, $employee->team_id);

        $this->actingAs($admin)
            ->put(route('employees.update', $employee), [
                'first_name' => 'Updated',
                'last_name' => $employee->last_name,
                'employment_type' => $employee->employment_type,
                'status' => $employee->status,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'first_name' => 'Updated']);

        $this->actingAs($admin)
            ->delete(route('employees.destroy', $employee))
            ->assertRedirect();

        $this->assertDatabaseMissing('employees', ['id' => $employee->id]);
    }

    public function test_team_owner_can_create_update_and_delete_employee(): void
    {
        $owner = $this->makeTeamOwner();

        $this->actingAs($owner)
            ->post(route('employees.store'), [
                'first_name' => 'Owner',
                'last_name' => 'Created',
                'employment_type' => 'full_time',
                'status' => 'active',
            ])
            ->assertRedirect();

        $employee = Employee::where('first_name', 'Owner')->where('last_name', 'Created')->firstOrFail();

        $this->actingAs($owner)
            ->delete(route('employees.destroy', $employee))
            ->assertRedirect();

        $this->assertDatabaseMissing('employees', ['id' => $employee->id]);
    }

    public function test_editor_team_member_cannot_approve_leave_request(): void
    {
        $owner = $this->makeTeamOwner();
        $editor = $this->makeTeamMember($owner, 'editor');

        $employee = Employee::factory()->create(['team_id' => $owner->currentTeam->id]);
        $leaveRequest = LeaveRequest::factory()->create([
            'team_id' => $owner->currentTeam->id,
            'employee_id' => $employee->id,
            'requested_by' => $owner->id,
        ]);

        $this->actingAs($editor)
            ->post(route('leave-requests.approve', $leaveRequest))
            ->assertForbidden();

        $this->assertDatabaseHas('leave_requests', ['id' => $leaveRequest->id, 'status' => 'pending']);
    }

    public function test_admin_team_member_can_approve_leave_request(): void
    {
        $owner = $this->makeTeamOwner();
        $admin = $this->makeTeamMember($owner, 'admin');

        $employee = Employee::factory()->create(['team_id' => $owner->currentTeam->id]);
        $leaveRequest = LeaveRequest::factory()->create([
            'team_id' => $owner->currentTeam->id,
            'employee_id' => $employee->id,
            'requested_by' => $owner->id,
        ]);

        $this->actingAs($admin)
            ->post(route('leave-requests.approve', $leaveRequest))
            ->assertRedirect();

        $this->assertDatabaseHas('leave_requests', ['id' => $leaveRequest->id, 'status' => 'approved']);
    }

    public function test_editor_team_member_cannot_create_or_update_position(): void
    {
        $owner = $this->makeTeamOwner();
        $editor = $this->makeTeamMember($owner, 'editor');

        $position = Position::factory()->create(['team_id' => $owner->currentTeam->id]);

        $this->assertFalse($editor->can('create', Position::class));
        $this->assertFalse($editor->can('update', $position));
        $this->assertFalse($editor->can('delete', $position));
        $this->assertTrue($editor->can('view', $position));
    }

    public function test_admin_team_member_can_create_and_update_position(): void
    {
        $owner = $this->makeTeamOwner();
        $admin = $this->makeTeamMember($owner, 'admin');

        $position = Position::factory()->create(['team_id' => $owner->currentTeam->id]);

        $this->assertTrue($admin->can('create', Position::class));
        $this->assertTrue($admin->can('update', $position));
        $this->assertTrue($admin->can('delete', $position));
    }
}
