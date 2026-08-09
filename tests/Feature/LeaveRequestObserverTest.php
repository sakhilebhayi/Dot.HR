<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveRequestObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_leave_request_notifies_admins_but_not_editors(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;

        $admin = User::factory()->create();
        $team->users()->attach($admin, ['role' => 'admin']);

        $editor = User::factory()->create();
        $team->users()->attach($editor, ['role' => 'editor']);

        $this->createLeaveRequest($team->id, $owner->id);

        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(1, $owner->fresh()->unreadNotifications()->count());
        $this->assertSame(1, $admin->fresh()->unreadNotifications()->count());
        $this->assertSame(0, $editor->fresh()->unreadNotifications()->count());
    }

    public function test_a_personal_team_with_one_admin_gets_exactly_one_notification(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();

        $this->createLeaveRequest($owner->currentTeam->id, $owner->id);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, $owner->fresh()->unreadNotifications()->count());
    }

    public function test_a_member_of_a_different_team_is_not_notified(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $otherTeamUser = User::factory()->withPersonalTeam()->create();

        $this->createLeaveRequest($owner->currentTeam->id, $owner->id);

        $this->assertSame(0, $otherTeamUser->fresh()->unreadNotifications()->count());
    }

    public function test_the_notification_payload_references_the_created_leave_request(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();

        $leaveRequest = $this->createLeaveRequest($owner->currentTeam->id, $owner->id);

        $notification = $owner->fresh()->notifications()->first();

        $this->assertSame('leave_request_submitted', $notification->data['type']);
        $this->assertSame($leaveRequest->id, $notification->data['leave_request_id']);
    }

    private function createLeaveRequest(int $teamId, int $requestedBy): LeaveRequest
    {
        $employee = Employee::factory()->create(['team_id' => $teamId]);

        return LeaveRequest::factory()->create([
            'team_id' => $teamId,
            'employee_id' => $employee->id,
            'requested_by' => $requestedBy,
        ]);
    }
}
