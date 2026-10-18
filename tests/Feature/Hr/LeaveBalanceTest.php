<?php

namespace Tests\Feature\Hr;

use App\Models\LeaveType;
use App\Services\Hr\LeaveBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

class LeaveBalanceTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    public function test_every_new_team_gets_the_default_leave_types(): void
    {
        $owner = $this->owner();

        $slugs = LeaveType::withoutTeamScope(fn () => LeaveType::where('team_id', $owner->currentTeam->id)->pluck('slug')->all());

        $this->assertEqualsCanonicalizing(['annual', 'sick', 'unpaid', 'other'], $slugs);
    }

    public function test_balance_is_entitlement_minus_approved_days_in_the_leave_year(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam);
        $type = $this->leaveType($owner->currentTeam);
        $type->update(['annual_days' => 15, 'carry_over_days' => 0]);

        $this->leaveFor($employee, '2026-03-02', '2026-03-06', ['status' => 'approved']);   // 5
        $this->leaveFor($employee, '2026-04-06', '2026-04-08', ['status' => 'approved']);   // 3
        $this->leaveFor($employee, '2026-05-04', '2026-05-05', ['status' => 'pending']);    // 2 pending, not used
        $this->leaveFor($employee, '2026-06-01', '2026-06-02', ['status' => 'denied']);     // ignored
        $this->leaveFor($employee, '2026-06-08', '2026-06-09', ['status' => 'cancelled']);  // ignored
        $this->leaveFor($employee, '2025-03-03', '2025-03-07', ['status' => 'approved']);   // last year

        $balance = app(LeaveBalance::class)->for($employee, $type, '2026-06-15');

        $this->assertTrue($balance->tracked);
        $this->assertSame(15.0, $balance->entitlement);
        $this->assertSame(8.0, $balance->used);
        $this->assertSame(2.0, $balance->pending);
        $this->assertSame(7.0, $balance->remaining);
        $this->assertSame(5.0, $balance->available, 'available also holds back pending days');
    }

    public function test_carry_over_is_capped_by_the_type_and_by_what_was_left_last_year(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam);
        $type = $this->leaveType($owner->currentTeam);
        $type->update(['annual_days' => 15, 'carry_over_days' => 5]);

        $this->leaveFor($employee, '2025-03-03', '2025-03-14', ['status' => 'approved']);  // 10 used in 2025, 5 left

        $this->assertSame(5.0, app(LeaveBalance::class)->for($employee, $type, '2026-02-01')->carriedOver);
        $this->assertSame(20.0, app(LeaveBalance::class)->for($employee, $type, '2026-02-01')->entitlement);

        $this->leaveFor($employee, '2025-06-02', '2025-06-06', ['status' => 'approved']);  // now 15 used, 0 left

        $this->assertSame(0.0, app(LeaveBalance::class)->for($employee, $type, '2026-02-01')->carriedOver);
    }

    public function test_carry_over_never_exceeds_the_cap_when_much_was_left(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam);
        $type = $this->leaveType($owner->currentTeam);
        $type->update(['annual_days' => 15, 'carry_over_days' => 3]);

        $this->assertSame(3.0, app(LeaveBalance::class)->for($employee, $type, '2026-02-01')->carriedOver);
    }

    public function test_a_type_without_annual_days_is_untracked(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam);
        $unpaid = $this->leaveType($owner->currentTeam, 'unpaid');

        $balance = app(LeaveBalance::class)->for($employee, $unpaid, '2026-03-01');

        $this->assertFalse($balance->tracked);
    }

    public function test_the_leave_year_start_month_is_configurable(): void
    {
        config(['hr.leave_year_start_month' => 4]);
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam);
        $type = $this->leaveType($owner->currentTeam);
        $type->update(['annual_days' => 15, 'carry_over_days' => 0]);

        $this->leaveFor($employee, '2026-03-02', '2026-03-06', ['status' => 'approved']);  // leave year 2025/26
        $this->leaveFor($employee, '2026-04-06', '2026-04-07', ['status' => 'approved']);  // leave year 2026/27

        $this->assertSame(2.0, app(LeaveBalance::class)->for($employee, $type, '2026-05-01')->used);
        $this->assertSame(5.0, app(LeaveBalance::class)->for($employee, $type, '2026-03-20')->used);
    }

    public function test_a_request_spanning_two_leave_years_counts_each_day_in_its_own_year(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam);
        $type = $this->leaveType($owner->currentTeam);
        $type->update(['annual_days' => 15, 'carry_over_days' => 0]);

        $this->leaveFor($employee, '2026-12-28', '2027-01-08', ['status' => 'approved']);  // 4 + 6

        $this->assertSame(4.0, app(LeaveBalance::class)->for($employee, $type, '2026-06-01')->used);
        $this->assertSame(6.0, app(LeaveBalance::class)->for($employee, $type, '2027-06-01')->used);
    }
}
