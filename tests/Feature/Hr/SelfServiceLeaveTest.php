<?php

namespace Tests\Feature\Hr;

use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

class SelfServiceLeaveTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-02 09:00:00'); // a Monday
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(): array
    {
        $owner = $this->owner();
        $user = $this->memberOf($owner, 'viewer');
        $employee = $this->employeeIn($owner->currentTeam, $user);
        $annual = $this->leaveType($owner->currentTeam);
        $annual->update(['annual_days' => 15, 'carry_over_days' => 0, 'enforce_balance' => true]);

        return [$owner, $user, $employee, $annual];
    }

    private function request(array $overrides = []): array
    {
        return array_merge([
            'start_date' => '2026-03-09',
            'end_date' => '2026-03-11',
            'reason' => 'family trip',
        ], $overrides);
    }

    public function test_a_linked_employee_can_request_leave_for_themselves(): void
    {
        [, $user, $employee, $annual] = $this->staff();

        $this->actingAs($user)
            ->post(route('leave-requests.store'), $this->request(['leave_type_id' => $annual->id]))
            ->assertRedirect(route('leave-requests.index'));

        $leave = LeaveRequest::firstOrFail();
        $this->assertSame($employee->id, $leave->employee_id);
        $this->assertSame($user->id, $leave->requested_by);
        $this->assertSame('pending', $leave->status);
        $this->assertSame('annual', $leave->type);
        $this->assertSame('family trip', $leave->reason);
        $this->assertSame(3.0, $leave->workingDays());
    }

    public function test_the_form_shows_leave_types_and_the_balance(): void
    {
        [, $user] = $this->staff();

        $this->actingAs($user)->get(route('leave-requests.create'))->assertOk()
            ->assertSee('Annual')->assertSee('15')->assertSee('Half day');
    }

    public function test_an_unlinked_member_cannot_request_leave(): void
    {
        $owner = $this->owner();
        $nobody = $this->memberOf($owner, 'viewer');

        $this->actingAs($nobody)->get(route('leave-requests.create'))->assertForbidden();
        $this->actingAs($nobody)->post(route('leave-requests.store'), $this->request(['leave_type_id' => $this->leaveType($owner->currentTeam)->id]))->assertForbidden();
        $this->assertSame(0, LeaveRequest::withoutGlobalScopes()->count());
    }

    public function test_a_self_service_user_cannot_request_leave_for_a_colleague(): void
    {
        [$owner, $user, , $annual] = $this->staff();
        $colleague = $this->employeeIn($owner->currentTeam, null, ['email' => 'colleague@example.test']);

        $this->actingAs($user)
            ->post(route('leave-requests.store'), $this->request(['leave_type_id' => $annual->id, 'employee_id' => $colleague->id]))
            ->assertForbidden();

        $this->assertSame(0, LeaveRequest::withoutGlobalScopes()->count());
    }

    public function test_an_admin_can_still_record_leave_on_behalf_of_an_employee(): void
    {
        [$owner, , $employee, $annual] = $this->staff();

        $this->actingAs($owner)
            ->post(route('leave-requests.store'), $this->request(['leave_type_id' => $annual->id, 'employee_id' => $employee->id]))
            ->assertRedirect();

        $leave = LeaveRequest::firstOrFail();
        $this->assertSame($employee->id, $leave->employee_id);
        $this->assertSame($owner->id, $leave->requested_by);
    }

    public function test_leave_type_must_belong_to_the_team(): void
    {
        [, $user] = $this->staff();
        $foreign = $this->leaveType($this->owner()->currentTeam);

        $this->actingAs($user)
            ->post(route('leave-requests.store'), $this->request(['leave_type_id' => $foreign->id]))
            ->assertSessionHasErrors('leave_type_id');
    }

    public function test_end_date_cannot_precede_start_date(): void
    {
        [, $user, , $annual] = $this->staff();

        $this->actingAs($user)
            ->post(route('leave-requests.store'), $this->request(['leave_type_id' => $annual->id, 'start_date' => '2026-03-11', 'end_date' => '2026-03-09']))
            ->assertSessionHasErrors('end_date');
    }

    public function test_a_range_without_working_days_is_refused(): void
    {
        [, $user, , $annual] = $this->staff();

        $this->actingAs($user)
            ->post(route('leave-requests.store'), $this->request(['leave_type_id' => $annual->id, 'start_date' => '2026-03-07', 'end_date' => '2026-03-08']))
            ->assertSessionHasErrors('end_date');

        $this->assertSame(0, LeaveRequest::count());
    }

    public function test_overlapping_the_employees_own_pending_or_approved_leave_is_refused(): void
    {
        [, $user, $employee, $annual] = $this->staff();
        $this->leaveFor($employee, '2026-03-09', '2026-03-11', ['status' => 'pending']);
        $this->leaveFor($employee, '2026-03-23', '2026-03-24', ['status' => 'approved']);

        foreach ([['2026-03-11', '2026-03-13'], ['2026-03-08', '2026-03-09'], ['2026-03-24', '2026-03-25'], ['2026-03-10', '2026-03-10']] as [$start, $end]) {
            $this->actingAs($user)
                ->post(route('leave-requests.store'), $this->request(['leave_type_id' => $annual->id, 'start_date' => $start, 'end_date' => $end]))
                ->assertSessionHasErrors('start_date');
        }

        $this->assertSame(2, LeaveRequest::count());
    }

    public function test_denied_cancelled_and_other_peoples_leave_does_not_block(): void
    {
        [$owner, $user, $employee, $annual] = $this->staff();
        $colleague = $this->employeeIn($owner->currentTeam, null, ['email' => 'colleague@example.test']);
        $this->leaveFor($employee, '2026-03-09', '2026-03-11', ['status' => 'denied']);
        $this->leaveFor($employee, '2026-03-09', '2026-03-11', ['status' => 'cancelled']);
        $this->leaveFor($colleague, '2026-03-09', '2026-03-11', ['status' => 'approved']);

        $this->actingAs($user)
            ->post(route('leave-requests.store'), $this->request(['leave_type_id' => $annual->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, LeaveRequest::where('status', 'pending')->count());
    }

    public function test_two_half_days_on_the_same_day_in_different_halves_are_allowed_but_not_the_same_half(): void
    {
        [, $user, , $annual] = $this->staff();
        $day = ['start_date' => '2026-03-09', 'end_date' => '2026-03-09', 'leave_type_id' => $annual->id];

        $this->actingAs($user)->post(route('leave-requests.store'), $this->request($day + ['half_day' => 'am']))->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('leave-requests.store'), $this->request($day + ['half_day' => 'pm']))->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('leave-requests.store'), $this->request($day + ['half_day' => 'am']))->assertSessionHasErrors('start_date');
        $this->actingAs($user)->post(route('leave-requests.store'), $this->request($day))->assertSessionHasErrors('start_date');

        $this->assertSame(2, LeaveRequest::count());
        $this->assertSame(0.5, LeaveRequest::where('half_day', 'am')->firstOrFail()->workingDays());
    }

    public function test_a_half_day_needs_a_single_day_range(): void
    {
        [, $user, , $annual] = $this->staff();

        $this->actingAs($user)
            ->post(route('leave-requests.store'), $this->request(['leave_type_id' => $annual->id, 'half_day' => 'am']))
            ->assertSessionHasErrors('half_day');
    }

    public function test_a_request_beyond_the_balance_is_blocked_for_a_type_that_enforces_it(): void
    {
        [, $user, $employee, $annual] = $this->staff();
        $this->leaveFor($employee, '2026-01-05', '2026-01-23', ['status' => 'approved']);   // 15 days used

        $this->actingAs($user)
            ->post(route('leave-requests.store'), $this->request(['leave_type_id' => $annual->id]))
            ->assertSessionHasErrors('leave_type_id');

        $this->assertSame(1, LeaveRequest::count());
    }

    public function test_pending_requests_hold_back_the_balance_too(): void
    {
        [, $user, $employee, $annual] = $this->staff();
        $this->leaveFor($employee, '2026-04-06', '2026-04-17', ['status' => 'pending']);    // 10 pending

        $this->actingAs($user)
            ->post(route('leave-requests.store'), $this->request(['leave_type_id' => $annual->id, 'start_date' => '2026-05-04', 'end_date' => '2026-05-12']))  // 7 more
            ->assertSessionHasErrors('leave_type_id');

        $this->actingAs($user)
            ->post(route('leave-requests.store'), $this->request(['leave_type_id' => $annual->id, 'start_date' => '2026-05-04', 'end_date' => '2026-05-08']))  // 5 more = 15
            ->assertSessionHasNoErrors();
    }

    public function test_a_type_that_does_not_enforce_the_balance_allows_going_over(): void
    {
        [, $user, $employee, $annual] = $this->staff();
        $annual->update(['enforce_balance' => false]);
        $this->leaveFor($employee, '2026-01-05', '2026-01-23', ['status' => 'approved']);

        $this->actingAs($user)
            ->post(route('leave-requests.store'), $this->request(['leave_type_id' => $annual->id]))
            ->assertSessionHasNoErrors();
    }

    public function test_an_unpaid_type_without_an_entitlement_is_always_allowed(): void
    {
        [$owner, $user] = $this->staff();
        $unpaid = $this->leaveType($owner->currentTeam, 'unpaid');

        $this->actingAs($user)
            ->post(route('leave-requests.store'), $this->request(['leave_type_id' => $unpaid->id, 'start_date' => '2026-06-01', 'end_date' => '2026-06-30']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, LeaveRequest::count());
    }

    public function test_a_terminated_employee_cannot_request_leave(): void
    {
        [, $user, $employee, $annual] = $this->staff();
        $employee->update(['status' => 'terminated']);

        $this->actingAs($user)
            ->post(route('leave-requests.store'), $this->request(['leave_type_id' => $annual->id]))
            ->assertForbidden();
    }

    public function test_the_employee_can_cancel_their_own_pending_request(): void
    {
        [, $user, $employee] = $this->staff();
        $leave = $this->leaveFor($employee, '2026-03-09', '2026-03-10');

        $this->actingAs($user)->post(route('leave-requests.cancel', $leave))->assertRedirect();

        $this->assertSame('cancelled', $leave->fresh()->status);
    }

    public function test_an_approved_or_decided_request_cannot_be_cancelled_by_the_employee(): void
    {
        [, $user, $employee] = $this->staff();
        $approved = $this->leaveFor($employee, '2026-03-09', '2026-03-10', ['status' => 'approved']);
        $denied = $this->leaveFor($employee, '2026-03-16', '2026-03-17', ['status' => 'denied']);

        $this->actingAs($user)->post(route('leave-requests.cancel', $approved))->assertForbidden();
        $this->actingAs($user)->post(route('leave-requests.cancel', $denied))->assertForbidden();

        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertSame('denied', $denied->fresh()->status);
    }

    public function test_nobody_else_can_cancel_someone_elses_pending_request(): void
    {
        [$owner, , $employee] = $this->staff();
        $other = $this->memberOf($owner, 'viewer');
        $this->employeeIn($owner->currentTeam, $other, ['email' => 'other@example.test']);
        $editor = $this->memberOf($owner, 'editor');
        $leave = $this->leaveFor($employee, '2026-03-09', '2026-03-10');

        $this->actingAs($other)->post(route('leave-requests.cancel', $leave))->assertForbidden();
        $this->actingAs($editor)->post(route('leave-requests.cancel', $leave))->assertForbidden();

        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_cancelling_twice_is_harmless(): void
    {
        [, $user, $employee] = $this->staff();
        $leave = $this->leaveFor($employee, '2026-03-09', '2026-03-10');

        $this->actingAs($user)->post(route('leave-requests.cancel', $leave))->assertRedirect();
        $this->actingAs($user)->post(route('leave-requests.cancel', $leave))->assertForbidden();

        $this->assertSame('cancelled', $leave->fresh()->status);
    }

    public function test_the_reason_is_optional(): void
    {
        [, $user, , $annual] = $this->staff();

        $this->actingAs($user)
            ->post(route('leave-requests.store'), ['leave_type_id' => $annual->id, 'start_date' => '2026-03-09', 'end_date' => '2026-03-09'])
            ->assertSessionHasNoErrors();

        $this->assertNull(LeaveRequest::firstOrFail()->reason);
    }
}
