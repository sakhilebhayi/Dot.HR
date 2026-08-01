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
 */
class HrAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeamOwner(): User
    {
        return User::factory()->withPersonalTeam()->create();
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
}
