<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The observer in App\Observers\LeaveRequestObserver must fire through
 * the real HTTP request path, not just via direct model/factory creation
 * — see docs/superpowers/specs/2026-08-09-leave-request-notification-wiring-design.md.
 *
 * Submitting a leave request is itself restricted to team admins
 * (LeaveRequestPolicy::create()), so the acting user in this test is an
 * admin — and per the spec's explicit design decision, that admin is
 * still notified alongside every other admin, with no self-exclusion.
 */
class LeaveRequestControllerNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_submitting_a_leave_request_through_the_controller_notifies_admins_only(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;

        $secondAdmin = User::factory()->create();
        $team->users()->attach($secondAdmin, ['role' => 'admin']);

        $editor = User::factory()->create();
        $team->users()->attach($editor, ['role' => 'editor']);

        $employee = Employee::factory()->create(['team_id' => $team->id]);

        $this->actingAs($owner)->post(route('leave-requests.store'), [
            'employee_id' => $employee->id,
            'type' => 'annual',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeeks(2)->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(1, $owner->fresh()->unreadNotifications()->count());
        $this->assertSame(1, $secondAdmin->fresh()->unreadNotifications()->count());
        $this->assertSame(0, $editor->fresh()->unreadNotifications()->count());
    }
}
