<?php

namespace Tests\Feature\Hr;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

class LeaveDecisionTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-02 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pending(): array
    {
        $owner = $this->owner();
        $admin = $this->memberOf($owner, 'admin');
        $staffUser = $this->memberOf($owner, 'viewer');
        $employee = $this->employeeIn($owner->currentTeam, $staffUser);
        $leave = $this->leaveFor($employee, '2026-03-09', '2026-03-10');

        return [$owner, $admin, $staffUser, $employee, $leave];
    }

    public function test_an_admin_approves_a_pending_request(): void
    {
        [, $admin, , , $leave] = $this->pending();

        $this->actingAs($admin)->post(route('leave-requests.approve', $leave))->assertRedirect();

        $leave->refresh();
        $this->assertSame('approved', $leave->status);
        $this->assertSame($admin->id, $leave->reviewed_by);
        $this->assertNotNull($leave->reviewed_at);
    }

    public function test_a_decided_request_cannot_be_flipped(): void
    {
        [$owner, $admin, , , $leave] = $this->pending();

        $this->actingAs($admin)->post(route('leave-requests.approve', $leave))->assertRedirect();
        $this->actingAs($owner)->post(route('leave-requests.deny', $leave))->assertRedirect()->assertSessionHas('error');

        $leave->refresh();
        $this->assertSame('approved', $leave->status);
        $this->assertSame($admin->id, $leave->reviewed_by, 'the first decision stands');
    }

    public function test_a_denied_request_cannot_be_approved_later(): void
    {
        [$owner, $admin, , , $leave] = $this->pending();

        $this->actingAs($admin)->post(route('leave-requests.deny', $leave))->assertRedirect();
        $this->actingAs($owner)->post(route('leave-requests.approve', $leave))->assertRedirect()->assertSessionHas('error');

        $this->assertSame('denied', $leave->fresh()->status);
    }

    public function test_a_cancelled_request_cannot_be_decided(): void
    {
        [, $admin, , , $leave] = $this->pending();
        $leave->update(['status' => 'cancelled']);

        $this->actingAs($admin)->post(route('leave-requests.approve', $leave))->assertRedirect()->assertSessionHas('error');

        $this->assertSame('cancelled', $leave->fresh()->status);
    }

    public function test_the_decision_runs_inside_a_transaction(): void
    {
        [, $admin, , , $leave] = $this->pending();
        $began = 0;
        Event::listen(TransactionBeginning::class, function () use (&$began) {
            $began++;
        });

        $this->actingAs($admin)->post(route('leave-requests.approve', $leave))->assertRedirect();

        $this->assertGreaterThanOrEqual(1, $began, 'approve must open a transaction (the row lock is checked on PostgreSQL by the manual run noted in the PR)');
        $this->assertSame('approved', $leave->fresh()->status);
    }

    public function test_the_employees_own_request_cannot_be_decided_by_the_employee_even_if_admin(): void
    {
        $owner = $this->owner();
        $adminUser = $this->memberOf($owner, 'admin');
        $employee = $this->employeeIn($owner->currentTeam, $adminUser);
        $leave = $this->leaveFor($employee, '2026-03-09', '2026-03-10');

        $this->actingAs($adminUser)->post(route('leave-requests.approve', $leave))->assertForbidden();
        $this->actingAs($adminUser)->post(route('leave-requests.deny', $leave))->assertForbidden();
        $this->assertSame('pending', $leave->fresh()->status);

        // a second admin can decide it
        $this->actingAs($owner)->post(route('leave-requests.approve', $leave))->assertRedirect();
        $this->assertSame('approved', $leave->fresh()->status);
    }

    public function test_a_request_an_admin_filed_cannot_be_decided_by_that_same_admin(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam);
        $leave = $this->leaveFor($employee, '2026-03-09', '2026-03-10', ['requested_by' => $owner->id]);

        $this->actingAs($owner)->post(route('leave-requests.approve', $leave))->assertForbidden();
        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_a_sole_admin_with_their_own_request_is_blocked_and_told_a_second_admin_is_needed(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam, $owner);
        $leave = $this->leaveFor($employee, '2026-03-09', '2026-03-10');

        $this->actingAs($owner)->get(route('leave-requests.show', $leave))->assertOk()
            ->assertSee('second admin')
            ->assertDontSee('>Approve<', false);
        $this->actingAs($owner)->post(route('leave-requests.approve', $leave))->assertForbidden();
    }

    public function test_approval_is_refused_when_the_balance_has_since_run_out(): void
    {
        [, $admin, , $employee, $leave] = $this->pending();
        $annual = $this->leaveType($employee->team);
        $annual->update(['annual_days' => 3, 'carry_over_days' => 0, 'enforce_balance' => true]);
        $this->leaveFor($employee, '2026-04-06', '2026-04-08', ['status' => 'approved']); // 3 used, leaves 0

        $this->actingAs($admin)->post(route('leave-requests.approve', $leave))->assertRedirect()->assertSessionHas('error');

        $this->assertSame('pending', $leave->fresh()->status);
        // it can still be denied
        $this->actingAs($admin)->post(route('leave-requests.deny', $leave))->assertRedirect();
        $this->assertSame('denied', $leave->fresh()->status);
    }

    public function test_on_leave_is_computed_from_approved_leave_in_the_window_only(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam);
        $this->leaveFor($employee, '2026-03-09', '2026-03-11', ['status' => 'approved']);
        $this->leaveFor($employee, '2026-03-02', '2026-03-03', ['status' => 'pending']);

        $employee = Employee::withoutTeamScope(fn () => Employee::find($employee->id));

        Carbon::setTestNow('2026-03-08 12:00:00');
        $this->assertFalse($employee->isOnLeave());
        $this->assertSame('active', $employee->displayStatus());

        Carbon::setTestNow('2026-03-10 12:00:00');
        $this->assertTrue($employee->isOnLeave());
        $this->assertSame('on_leave', $employee->displayStatus());

        Carbon::setTestNow('2026-03-12 12:00:00');
        $this->assertFalse($employee->isOnLeave());
        $this->assertSame('active', $employee->displayStatus());
    }

    public function test_pending_and_denied_leave_never_marks_someone_on_leave(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam);
        $this->leaveFor($employee, '2026-03-02', '2026-03-03', ['status' => 'pending']);
        $this->leaveFor($employee, '2026-03-02', '2026-03-03', ['status' => 'denied']);
        $this->leaveFor($employee, '2026-03-02', '2026-03-03', ['status' => 'cancelled']);

        $this->assertFalse(Employee::withoutTeamScope(fn () => Employee::find($employee->id))->isOnLeave());
    }

    public function test_a_terminated_employee_stays_terminated_not_on_leave(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam, null, ['status' => 'terminated']);
        $this->leaveFor($employee, '2026-03-02', '2026-03-03', ['status' => 'approved']);

        $this->assertSame('terminated', Employee::withoutTeamScope(fn () => Employee::find($employee->id))->displayStatus());
    }

    public function test_the_dashboard_counts_on_leave_from_the_leave_window_not_a_stored_flag(): void
    {
        $owner = $this->owner();
        $away = $this->employeeIn($owner->currentTeam, null, ['email' => 'away@example.test']);
        $stale = $this->employeeIn($owner->currentTeam, null, ['email' => 'stale@example.test']);
        DB::table('employees')->where('id', $stale->id)->update(['status' => 'on_leave']); // legacy stale flag
        $this->leaveFor($away, '2026-03-02', '2026-03-04', ['status' => 'approved']);

        $this->actingAs($owner)->get(route('dashboard'))->assertOk()
            ->assertViewHas('onLeaveCount', 1)
            ->assertViewHas('headcount', 2);
    }

    public function test_the_edit_form_no_longer_offers_a_stored_on_leave_status(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam);

        $this->actingAs($owner)->get(route('employees.edit', $employee))->assertOk()
            ->assertDontSee('value="on_leave"', false);
        $this->actingAs($owner)->put(route('employees.update', $employee), [
            'first_name' => 'A', 'last_name' => 'B', 'employment_type' => 'full_time', 'status' => 'on_leave',
        ])->assertSessionHasErrors('status');
    }
}
