<?php

namespace Tests\Feature\Hr;

use App\Models\Employee;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

class SoftDeleteRetentionTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_deleting_an_employee_soft_deletes_and_keeps_leave_history(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam);
        $leave = $this->leaveFor($employee, '2026-03-09', '2026-03-10');

        $this->actingAs($owner)->delete(route('employees.destroy', $employee))->assertRedirect();

        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
        $this->assertDatabaseHas('leave_requests', ['id' => $leave->id, 'deleted_at' => null]);
    }

    public function test_removed_employees_disappear_from_lists_but_can_be_restored_by_an_admin(): void
    {
        $owner = $this->owner();
        $editor = $this->memberOf($owner, 'editor');
        $employee = $this->employeeIn($owner->currentTeam, null, ['first_name' => 'Gone', 'last_name' => 'Fishing']);
        $this->leaveFor($employee, '2026-03-09', '2026-03-10');
        $employee->delete();

        $this->actingAs($owner)->get(route('employees.index'))->assertDontSee('Fishing');
        $this->actingAs($owner)->get(route('leave-requests.index'))->assertDontSee('Fishing');
        $this->actingAs($owner)->get(route('employees.index', ['removed' => 1]))->assertSee('Fishing')->assertSee('Restore');
        $this->actingAs($editor)->get(route('employees.index', ['removed' => 1]))->assertDontSee('Fishing');

        $this->actingAs($editor)->post(route('employees.restore', $employee->id))->assertForbidden();
        $this->actingAs($owner)->post(route('employees.restore', $employee->id))->assertRedirect();

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'deleted_at' => null]);
        $this->actingAs($owner)->get(route('employees.index'))->assertSee('Fishing');
        $this->assertCount(1, DB::table('hr_audit_log')->where('action', 'employee.restored')->get());
    }

    public function test_restoring_is_refused_if_the_email_is_now_taken(): void
    {
        $owner = $this->owner();
        $old = $this->employeeIn($owner->currentTeam, null, ['email' => 'same@example.test']);
        $old->delete();
        $this->employeeIn($owner->currentTeam, null, ['email' => 'same@example.test']);

        $this->actingAs($owner)->post(route('employees.restore', $old->id))->assertSessionHasErrors('email');

        $this->assertSoftDeleted('employees', ['id' => $old->id]);
    }

    public function test_the_retention_default_is_three_years(): void
    {
        $this->assertSame(1095, config('hr.retention_days'));
    }

    public function test_purge_removes_only_soft_deleted_rows_older_than_the_retention_period(): void
    {
        config(['hr.retention_days' => 100]);
        $owner = $this->owner();
        $team = $owner->currentTeam;

        Carbon::setTestNow('2026-01-01 00:00:00');
        $old = $this->employeeIn($team, null, ['email' => 'old@example.test']);
        $oldLeave = $this->leaveFor($old, '2025-12-01', '2025-12-02');
        $old->delete();                                            // deleted 2026-01-01

        Carbon::setTestNow('2026-03-15 00:00:00');
        $recent = $this->employeeIn($team, null, ['email' => 'recent@example.test']);
        $recent->delete();                                         // deleted 2026-03-15

        $live = $this->employeeIn($team, null, ['email' => 'live@example.test']);
        $liveLeave = $this->leaveFor($live, '2026-03-09', '2026-03-10');
        $deletedLeaveOld = $this->leaveFor($live, '2026-02-02', '2026-02-03');
        Carbon::setTestNow('2026-01-02 00:00:00');
        $deletedLeaveOld->delete();                                // soft-deleted leave, old
        Carbon::setTestNow('2026-03-15 00:00:00');
        $deletedLeaveRecent = $this->leaveFor($live, '2026-04-06', '2026-04-07');
        $deletedLeaveRecent->delete();

        Carbon::setTestNow('2026-04-15 00:00:00');                 // 104 days after 2026-01-01, 31 after 03-15
        $this->artisan('hr:purge-expired')->assertSuccessful();

        $this->assertDatabaseMissing('employees', ['id' => $old->id]);
        $this->assertDatabaseMissing('leave_requests', ['id' => $oldLeave->id]);
        $this->assertDatabaseMissing('leave_requests', ['id' => $deletedLeaveOld->id]);
        $this->assertSoftDeleted('employees', ['id' => $recent->id]);
        $this->assertSoftDeleted('leave_requests', ['id' => $deletedLeaveRecent->id]);
        $this->assertDatabaseHas('employees', ['id' => $live->id]);
        $this->assertDatabaseHas('leave_requests', ['id' => $liveLeave->id]);
    }

    public function test_purge_writes_an_audit_entry_without_personal_data(): void
    {
        config(['hr.retention_days' => 10]);
        $owner = $this->owner();
        Carbon::setTestNow('2026-01-01 00:00:00');
        $employee = $this->employeeIn($owner->currentTeam, null, [
            'first_name' => 'Purgeable', 'email' => 'purge.me@example.test', 'phone' => '+27 11 000 0000',
        ]);
        $leave = $this->leaveFor($employee, '2025-12-01', '2025-12-02', ['reason' => 'to be erased']);
        $employee->delete();

        Carbon::setTestNow('2026-02-01 12:00:00');
        $this->artisan('hr:purge-expired')->assertSuccessful();

        $purged = DB::table('hr_audit_log')->where('action', 'employee.purged')->get();
        $this->assertCount(1, $purged);
        $this->assertSame($employee->id, (int) $purged->first()->subject_id);
        $this->assertNull($purged->first()->actor_user_id);
        $this->assertSame('2026-02-01 12:00:00', Carbon::parse($purged->first()->created_at)->toDateTimeString());
        $this->assertCount(1, DB::table('hr_audit_log')->where('action', 'leave.purged')->get());

        $dump = json_encode(DB::table('hr_audit_log')->get());
        foreach (['Purgeable', 'purge.me', '+27 11', 'to be erased'] as $needle) {
            $this->assertStringNotContainsString($needle, $dump);
        }
    }

    public function test_purge_never_touches_audit_rows(): void
    {
        config(['hr.retention_days' => 1]);
        $owner = $this->owner();
        Carbon::setTestNow('2020-01-01');
        $employee = $this->employeeIn($owner->currentTeam);
        $employee->delete();
        $before = DB::table('hr_audit_log')->count();

        Carbon::setTestNow('2026-01-01');
        $this->artisan('hr:purge-expired')->assertSuccessful();

        $this->assertGreaterThanOrEqual($before, DB::table('hr_audit_log')->count());
        $this->assertSame(1, DB::table('hr_audit_log')->where('action', 'employee.created')->count());
    }

    public function test_dry_run_reports_but_deletes_nothing(): void
    {
        config(['hr.retention_days' => 1]);
        $owner = $this->owner();
        Carbon::setTestNow('2026-01-01');
        $employee = $this->employeeIn($owner->currentTeam);
        $employee->delete();

        Carbon::setTestNow('2026-03-01');
        $this->artisan('hr:purge-expired --dry-run')->expectsOutputToContain('1 employee')->assertSuccessful();

        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
        $this->assertSame(0, DB::table('hr_audit_log')->where('action', 'employee.purged')->count());
    }

    public function test_nothing_to_purge_writes_nothing(): void
    {
        $owner = $this->owner();
        $this->employeeIn($owner->currentTeam);
        $before = DB::table('hr_audit_log')->count();

        $this->artisan('hr:purge-expired')->assertSuccessful();

        $this->assertSame($before, DB::table('hr_audit_log')->count());
    }

    public function test_purge_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command, 'hr:purge-expired'));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression);
    }

    public function test_purge_works_across_every_team_despite_the_team_scope(): void
    {
        config(['hr.retention_days' => 1]);
        $a = $this->owner();
        $b = $this->owner();
        Carbon::setTestNow('2026-01-01');
        $ea = $this->employeeIn($a->currentTeam);
        $eb = $this->employeeIn($b->currentTeam);
        $ea->delete();
        $eb->delete();

        Carbon::setTestNow('2026-03-01');
        $this->actingAs($a);   // even a logged-in context must not narrow the purge
        $this->artisan('hr:purge-expired')->assertSuccessful();

        $this->assertDatabaseMissing('employees', ['id' => $ea->id]);
        $this->assertDatabaseMissing('employees', ['id' => $eb->id]);
        $this->assertSame(0, Employee::withoutGlobalScopes()->withTrashed()->count());
        $this->assertSame(0, LeaveRequest::withoutGlobalScopes()->withTrashed()->count());
    }

    public function test_deleting_the_account_that_filed_a_request_keeps_the_request(): void
    {
        $owner = $this->owner();
        $filer = $this->memberOf($owner, 'viewer');
        $employee = $this->employeeIn($owner->currentTeam, $filer);
        $leave = $this->leaveFor($employee, '2026-03-09', '2026-03-10');

        $filer->delete();

        $this->assertNull(DB::table('leave_requests')->where('id', $leave->id)->value('requested_by'));
        $this->assertNull(DB::table('employees')->where('id', $employee->id)->value('user_id'));
        $this->actingAs($owner)->get(route('leave-requests.show', $leave))->assertOk()->assertSee('A former user');
    }

    public function test_restoring_a_record_that_is_not_removed_does_nothing_and_logs_nothing(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam);

        $this->actingAs($owner)->post(route('employees.restore', $employee->id))->assertRedirect();

        $this->assertCount(0, DB::table('hr_audit_log')->where('action', 'employee.restored')->get());
    }
}
