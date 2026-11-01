<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\HrAuditLog;
use App\Models\HrGrant;
use App\Models\PayrollRun;
use App\Models\Position;
use App\Models\Resignation;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * Payroll inputs (brief §11): starters, leavers, approved hours and leave for a period, and HR's
 * sign-off. Nothing is calculated; contractors are left out; open items block the sign-off.
 */
class PayrollInputsTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    /** @return object{owner: User, supervisor: User, thandi: Employee, sipho: Employee, ayanda: Employee} */
    private function team(): object
    {
        $owner = $this->owner();
        $team = $owner->currentTeam;
        $team->forceFill(['weekly_ordinary_hours' => 45])->save();
        $supervisor = $this->memberOf($owner, 'viewer');
        HrGrant::create(['team_id' => $team->id, 'user_id' => $supervisor->id, 'capability' => HrGrant::SUPERVISOR, 'granted_by' => $owner->id]);
        $cashier = Position::factory()->create(['team_id' => $team->id, 'title' => 'Cashier', 'department' => 'Shop floor']);

        $thandi = $this->employeeIn($team, null, ['first_name' => 'Thandi', 'last_name' => 'Mokoena', 'position_id' => $cashier->id, 'start_date' => '2026-09-10', 'employment_type' => 'full_time']);
        $sipho = $this->employeeIn($team, null, ['first_name' => 'Sipho', 'last_name' => 'Dlamini', 'start_date' => '2024-01-01', 'end_date' => '2026-09-30', 'employment_type' => 'full_time']);
        $ayanda = $this->employeeIn($team, null, ['first_name' => 'Ayanda', 'last_name' => 'Khumalo', 'start_date' => '2023-03-01', 'employment_type' => 'part_time']);
        $this->employeeIn($team, null, ['first_name' => 'Zola', 'employment_type' => 'contractor', 'start_date' => '2025-01-01']);
        $this->employeeIn($team, null, ['first_name' => 'Lindiwe', 'start_date' => '2020-01-01', 'end_date' => '2026-08-15', 'status' => 'terminated']);

        $resignation = new Resignation;
        $resignation->forceFill(['team_id' => $team->id, 'employee_id' => $ayanda->id, 'given_at' => '2026-08-20 09:00:00', 'last_day' => '2026-09-25',
            'minimum_notice_days' => 28, 'status' => 'acknowledged', 'agreed_last_day' => '2026-09-18'])->save();

        foreach ([['2026-08-31', 'approved', 2700, 0], ['2026-09-07', 'approved', 2900, 200], ['2026-09-14', 'submitted', 2700, 0]] as [$week, $status, $worked, $over]) {
            $sheet = new Timesheet;
            $sheet->forceFill(['team_id' => $team->id, 'employee_id' => $sipho->id, 'week_start' => $week, 'status' => $status, 'worked_minutes' => $worked, 'overtime_minutes' => $over])->save();
        }

        // Annual leave across the month end counts only its September working days (28, 29, 30).
        $this->leaveFor($sipho, '2026-09-28', '2026-10-02', ['status' => 'approved']);
        $this->leaveFor($thandi, '2026-09-14', '2026-09-15', ['status' => 'approved', 'leave_type_id' => $this->leaveType($team, 'unpaid')->id, 'type' => 'unpaid']);

        return (object) compact('owner', 'supervisor', 'thandi', 'sipho', 'ayanda');
    }

    public function test_the_period_lists_starters_leavers_hours_and_leave_and_leaves_contractors_out(): void
    {
        $t = $this->team();
        $this->actingAs($t->supervisor)->get(route('payroll.index', ['month' => '2026-09']))->assertForbidden();

        $page = $this->actingAs($t->owner)->get(route('payroll.index', ['month' => '2026-09']))->assertOk();
        $page->assertSee('3 people')->assertSee('Starts 10 Sep')->assertSee('Leaves 30 Sep')->assertSee('Leaves 18 Sep')->assertSee('Resigned, last day agreed')
            ->assertSee('1 week not approved')->assertSee('1 timesheet waiting for approval')->assertDontSee('Zola')->assertDontSee('Lindiwe');

        $body = $this->actingAs($t->owner)->get(route('payroll.export', ['month' => '2026-09']))->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\r\n", substr($body, 3))));
        $this->assertCount(4, $lines);
        $rows = collect(array_slice($lines, 1))->map(fn ($l) => str_getcsv($l, ',', '"', ''))->keyBy(fn ($r) => explode(' ', $r[0])[0]);

        // Sipho: only the week starting in September and approved; three September leave days, paid.
        $this->assertSame(['', '2026-09-30', 'End date', '48.33', '3.33', '1', '3', '0', '0', 'Annual leave 3'], array_slice($rows['Sipho'], 4));
        $this->assertSame(['Cashier', 'Shop floor', 'Full-time', '2026-09-10'], array_slice($rows['Thandi'], 1, 4));
        $this->assertSame(['0', '2', 'Unpaid leave 2'], [$rows['Thandi'][10], $rows['Thandi'][11], $rows['Thandi'][13]]);
        $this->assertSame(['Part-time', '2026-09-18', 'Resigned, last day agreed'], [$rows['Ayanda'][3], $rows['Ayanda'][5], $rows['Ayanda'][6]]);
        $this->assertTrue(HrAuditLog::withoutTeamScope(fn () => HrAuditLog::query()->where('action', 'payroll.inputs_exported')->exists()));
    }

    public function test_sign_off_waits_for_open_items_and_notices_later_changes(): void
    {
        $t = $this->team();
        $this->actingAs($t->owner)->post(route('payroll.sign-off', ['month' => '2026-09']))->assertSessionHasErrors('period');

        Timesheet::withoutTeamScope(fn () => Timesheet::query()->where('status', 'submitted')->update(['status' => 'approved']));
        $this->actingAs($t->owner)->post(route('payroll.sign-off', ['month' => '2026-09']), ['note' => 'Sent on the 25th'])->assertRedirect()->assertSessionHasNoErrors();
        $run = PayrollRun::withoutTeamScope(fn () => PayrollRun::query()->sole());
        $this->assertSame([3, '2026-09-01', '2026-09-30'], [$run->people, $run->period_start->toDateString(), $run->period_end->toDateString()]);

        $this->flushSession();
        $this->actingAs($t->owner)->get(route('payroll.index', ['month' => '2026-09']))->assertSee('Nothing has changed since')->assertSee('Sent on the 25th');

        // Leave approved after the sign-off changes the inputs, and the page says so.
        $this->leaveFor($t->ayanda, '2026-09-08', '2026-09-08', ['status' => 'approved']);
        $this->actingAs($t->owner)->get(route('payroll.index', ['month' => '2026-09']))->assertSee('Something changed after the sign-off')->assertDontSee('Nothing has changed since');
        $this->actingAs($t->owner)->get(route('payroll.index', ['from' => '2026-09-01', 'to' => '2026-12-31']))->assertSessionHasErrors('to');
    }
}
