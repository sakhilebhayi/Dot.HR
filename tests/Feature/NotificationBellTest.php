<?php

namespace Tests\Feature;

use App\Livewire\NotificationBell;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveRequestSubmittedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_notification_bell_for_authenticated_user(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeLivewire('notification-bell');
    }

    public function test_unread_count_reflects_database_notifications(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $employee = Employee::factory()->create(['team_id' => $team->id]);
        $leaveRequest = LeaveRequest::factory()->create([
            'team_id' => $team->id,
            'employee_id' => $employee->id,
            'requested_by' => $user->id,
        ]);

        $user->notify(new LeaveRequestSubmittedNotification($leaveRequest));

        $this->assertDatabaseCount('notifications', 1);

        Livewire::actingAs($user)
            ->test(NotificationBell::class)
            ->assertSet('open', false)
            ->call('toggle')
            ->assertSet('open', true)
            ->assertSee('Leave request pending');

        $this->assertEquals(1, $user->fresh()->unreadNotifications()->count());
    }

    public function test_mark_all_as_read_clears_unread_count(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $employee = Employee::factory()->create(['team_id' => $team->id]);
        $leaveRequest = LeaveRequest::factory()->create([
            'team_id' => $team->id,
            'employee_id' => $employee->id,
            'requested_by' => $user->id,
        ]);

        $user->notify(new LeaveRequestSubmittedNotification($leaveRequest));

        Livewire::actingAs($user)
            ->test(NotificationBell::class)
            ->call('markAllAsRead');

        $this->assertEquals(0, $user->fresh()->unreadNotifications()->count());
    }
}
