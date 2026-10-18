<?php

namespace Tests\Feature\Hr;

use App\Models\Employee;
use App\Models\HrAuditLog;
use App\Models\LeaveRequest;
use App\Services\Hr\Audit;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

class AuditTrailTest extends TestCase
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

    private function entries(?string $action = null)
    {
        $q = DB::table('hr_audit_log')->orderBy('id');

        return $action ? $q->where('action', $action)->get() : $q->get();
    }

    public function test_creating_updating_and_deleting_an_employee_is_logged_with_field_names(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('employees.store'), [
            'first_name' => 'Zed', 'last_name' => 'Zulu', 'email' => 'zed@example.test', 'phone' => '+27 71 555 0000',
            'employment_type' => 'full_time', 'status' => 'active',
        ])->assertRedirect();
        $employee = Employee::firstOrFail();

        $this->assertCount(1, $this->entries('employee.created'));

        $this->actingAs($owner)->put(route('employees.update', $employee), [
            'first_name' => 'Zed', 'last_name' => 'Zulu', 'email' => 'zed@example.test', 'phone' => '+27 71 555 9999',
            'employment_type' => 'part_time', 'status' => 'active',
        ])->assertRedirect();

        $updated = $this->entries('employee.updated')->first();
        $this->assertNotNull($updated);
        $fields = json_decode($updated->fields, true);
        $this->assertEqualsCanonicalizing(['phone', 'employment_type'], $fields);
        $changes = json_decode($updated->changes, true);
        $this->assertSame(['from' => 'full_time', 'to' => 'part_time'], $changes['employment_type']);
        $this->assertArrayNotHasKey('phone', $changes, 'contact details are named but never copied');

        $this->actingAs($owner)->delete(route('employees.destroy', $employee))->assertRedirect();
        $this->assertCount(1, $this->entries('employee.deleted'));

        $row = $this->entries('employee.created')->first();
        $this->assertSame($owner->id, (int) $row->actor_user_id);
        $this->assertSame($owner->currentTeam->id, (int) $row->team_id);
        $this->assertSame(Employee::class, $row->subject_type);
        $this->assertSame($employee->id, (int) $row->subject_id);
        $this->assertNotNull($row->ip);
        $this->assertSame('2026-03-02 09:00:00', Carbon::parse($row->created_at)->toDateTimeString());
    }

    public function test_leave_creation_decision_and_cancellation_are_logged(): void
    {
        $owner = $this->owner();
        $user = $this->memberOf($owner, 'viewer');
        $employee = $this->employeeIn($owner->currentTeam, $user);
        $annual = $this->leaveType($owner->currentTeam);

        $this->actingAs($user)->post(route('leave-requests.store'), [
            'leave_type_id' => $annual->id, 'start_date' => '2026-03-09', 'end_date' => '2026-03-10', 'reason' => 'dentist abscess',
        ])->assertRedirect();
        $first = LeaveRequest::firstOrFail();
        $this->assertCount(1, $this->entries('leave.created'));

        $this->actingAs($owner)->post(route('leave-requests.approve', $first))->assertRedirect();
        $this->assertCount(1, $this->entries('leave.approved'));

        $second = $this->leaveFor($employee, '2026-04-06', '2026-04-07');
        $this->actingAs($owner)->post(route('leave-requests.deny', $second))->assertRedirect();
        $this->assertCount(1, $this->entries('leave.denied'));

        $third = $this->leaveFor($employee, '2026-05-04', '2026-05-05');
        $this->actingAs($user)->post(route('leave-requests.cancel', $third))->assertRedirect();
        $this->assertCount(1, $this->entries('leave.cancelled'));

        $decided = $this->entries('leave.approved')->first();
        $this->assertSame($owner->id, (int) $decided->actor_user_id);
        $changes = json_decode($decided->changes, true);
        $this->assertSame(['from' => 'pending', 'to' => 'approved'], $changes['status']);
    }

    public function test_deleting_a_leave_request_is_logged(): void
    {
        $owner = $this->owner();
        $leave = $this->leaveFor($this->employeeIn($owner->currentTeam), '2026-03-09', '2026-03-10');

        $this->actingAs($owner)->delete(route('leave-requests.destroy', $leave))->assertRedirect();

        $this->assertCount(1, $this->entries('leave.deleted'));
        $this->assertSoftDeleted('leave_requests', ['id' => $leave->id]);
    }

    public function test_the_audit_log_never_contains_a_reason_phone_or_email(): void
    {
        $owner = $this->owner();
        $user = $this->memberOf($owner, 'viewer');
        $employee = $this->employeeIn($owner->currentTeam, $user, ['email' => 'secret.person@example.test', 'phone' => '+27 60 123 4567']);
        $leave = $this->leaveFor($employee, '2026-03-09', '2026-03-10', ['reason' => 'chemotherapy appointment']);

        $this->actingAs($owner)->get(route('leave-requests.show', $leave))->assertOk();
        $this->actingAs($owner)->get(route('employees.show', $employee))->assertOk();
        $this->actingAs($owner)->post(route('leave-requests.approve', $leave));
        $this->actingAs($owner)->put(route('employees.update', $employee), [
            'first_name' => 'Renamed', 'last_name' => 'Person', 'email' => 'new.address@example.test', 'phone' => '+27 60 999 0000',
            'employment_type' => 'full_time', 'status' => 'active',
        ]);

        $dump = json_encode(DB::table('hr_audit_log')->get());
        foreach (['chemotherapy', 'secret.person', '+27 60 123', 'new.address', '+27 60 999', 'Renamed'] as $needle) {
            $this->assertStringNotContainsString($needle, $dump);
        }
        $this->assertGreaterThan(3, $this->entries()->count());
    }

    public function test_an_admin_viewing_someone_elses_leave_reason_is_logged_as_an_access_event(): void
    {
        $owner = $this->owner();
        $user = $this->memberOf($owner, 'viewer');
        $employee = $this->employeeIn($owner->currentTeam, $user);
        $leave = $this->leaveFor($employee, '2026-03-09', '2026-03-10', ['reason' => 'private']);

        $this->actingAs($owner)->get(route('leave-requests.show', $leave))->assertOk();

        $views = $this->entries('leave.viewed');
        $this->assertCount(1, $views);
        $this->assertSame($owner->id, (int) $views->first()->actor_user_id);
        $this->assertSame($leave->id, (int) $views->first()->subject_id);
        $this->assertTrue(json_decode($views->first()->changes, true)['reason_visible']);
    }

    public function test_an_editor_viewing_leave_is_logged_with_the_reason_marked_not_visible(): void
    {
        $owner = $this->owner();
        $editor = $this->memberOf($owner, 'editor');
        $leave = $this->leaveFor($this->employeeIn($owner->currentTeam), '2026-03-09', '2026-03-10', ['reason' => 'private']);

        $this->actingAs($editor)->get(route('leave-requests.show', $leave))->assertOk();

        $this->assertFalse(json_decode($this->entries('leave.viewed')->first()->changes, true)['reason_visible']);
    }

    public function test_the_subject_viewing_their_own_leave_or_record_is_not_logged(): void
    {
        $owner = $this->owner();
        $user = $this->memberOf($owner, 'viewer');
        $employee = $this->employeeIn($owner->currentTeam, $user);
        $leave = $this->leaveFor($employee, '2026-03-09', '2026-03-10');

        $this->actingAs($user)->get(route('leave-requests.show', $leave))->assertOk();
        $this->actingAs($user)->get(route('employees.show', $employee))->assertOk();

        $this->assertCount(0, $this->entries('leave.viewed'));
        $this->assertCount(0, $this->entries('employee.viewed'));
    }

    public function test_viewing_an_employee_detail_by_someone_else_is_logged(): void
    {
        $owner = $this->owner();
        $editor = $this->memberOf($owner, 'editor');
        $employee = $this->employeeIn($owner->currentTeam);

        $this->actingAs($owner)->get(route('employees.show', $employee))->assertOk();
        $this->actingAs($editor)->get(route('employees.show', $employee))->assertOk();

        $this->assertCount(2, $this->entries('employee.viewed'));
    }

    public function test_a_refused_view_is_not_logged_as_a_view(): void
    {
        $owner = $this->owner();
        $stranger = $this->memberOf($owner, 'viewer');
        $employee = $this->employeeIn($owner->currentTeam);

        $this->actingAs($stranger)->get(route('employees.show', $employee))->assertForbidden();

        $this->assertCount(0, $this->entries('employee.viewed'));
    }

    public function test_audit_rows_cannot_be_updated_or_deleted_through_the_model(): void
    {
        $owner = $this->owner();
        $this->employeeIn($owner->currentTeam);
        $row = HrAuditLog::withoutGlobalScopes()->firstOrFail();

        try {
            $row->update(['action' => 'tampered']);
            $this->fail('update must be refused');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $row->delete();
            $this->fail('delete must be refused');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $row->forceDelete();
            $this->fail('forceDelete must be refused');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }

        $this->assertNotSame('tampered', DB::table('hr_audit_log')->first()->action);
    }

    public function test_audit_page_is_admin_only_and_filterable(): void
    {
        $owner = $this->owner();
        $editor = $this->memberOf($owner, 'editor');
        $employee = $this->employeeIn($owner->currentTeam);
        $this->actingAs($owner)->get(route('employees.show', $employee));
        $this->actingAs($owner)->delete(route('employees.destroy', $employee));

        $this->actingAs($editor)->get(route('audit-log.index'))->assertForbidden();

        $this->actingAs($owner)->get(route('audit-log.index'))->assertOk()
            ->assertSee('data-action="employee.viewed"', false)->assertSee('data-action="employee.deleted"', false);

        $this->actingAs($owner)->get(route('audit-log.index', ['action' => 'employee.deleted']))->assertOk()
            ->assertSee('data-action="employee.deleted"', false)->assertDontSee('data-action="employee.viewed"', false);

        $this->actingAs($owner)->get(route('audit-log.index', ['actor' => $owner->id, 'from' => '2026-03-02', 'to' => '2026-03-02']))
            ->assertOk()->assertSee('data-action="employee.deleted"', false);

        $this->actingAs($owner)->get(route('audit-log.index', ['from' => '2026-04-01']))->assertOk()
            ->assertDontSee('data-action="employee.deleted"', false);

        $this->actingAs($owner)->get(route('audit-log.index', ['subject' => 'leaverequest']))->assertOk()
            ->assertDontSee('data-action="employee.deleted"', false);
    }

    public function test_audit_page_shows_only_the_current_teams_entries(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $this->employeeIn($other->currentTeam, null, ['first_name' => 'Elsewhere']);
        $mine = $this->employeeIn($owner->currentTeam);
        $this->actingAs($owner)->get(route('employees.show', $mine));

        $page = $this->actingAs($owner)->get(route('audit-log.index'))->assertOk();
        $this->assertSame(
            DB::table('hr_audit_log')->where('team_id', $owner->currentTeam->id)->count(),
            substr_count($page->getContent(), 'data-audit-row')
        );
    }

    public function test_rows_written_outside_a_web_request_carry_no_ip_and_web_rows_do(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam, null, ['employment_type' => 'full_time']);

        $this->assertNull($this->entries('employee.created')->first()->ip, 'console and factory work has no client');

        $this->actingAs($owner)->get(route('employees.show', $employee))->assertOk();

        $this->assertSame('127.0.0.1', $this->entries('employee.viewed')->first()->ip);

        $employee->update(['employment_type' => 'contractor']);
        $this->assertNull($this->entries('employee.updated')->first()->ip, 'the request has ended: no stale IP leaks into later work');
    }

    public function test_system_work_records_neither_actor_nor_ip_even_if_someone_is_signed_in(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        Audit::asSystem(fn () => $this->employeeIn($owner->currentTeam));

        $row = $this->entries('employee.created')->first();
        $this->assertNull($row->actor_user_id);
        $this->assertNull($row->ip);
    }

    public function test_leave_type_changes_are_logged_with_before_and_after_but_seeding_is_not(): void
    {
        $owner = $this->owner();
        $this->assertCount(0, $this->entries('leave_type.created'), 'default types seeded for a new team are not an admin action');
        $annual = $this->leaveType($owner->currentTeam);

        $this->actingAs($owner)->put(route('leave-types.update', $annual), [
            'name' => 'Annual leave', 'annual_days' => 18, 'carry_over_days' => 5, 'is_paid' => 1, 'enforce_balance' => 1,
        ])->assertRedirect();

        $entry = $this->entries('leave_type.updated')->first();
        $this->assertNotNull($entry);
        $change = json_decode($entry->changes, true)['annual_days'] ?? [];
        $this->assertSame([15.0, 18.0], [(float) ($change['from'] ?? null), (float) ($change['to'] ?? null)]);
    }

    public function test_the_subject_filter_matches_exactly_on_every_database(): void
    {
        $owner = $this->owner();
        $leave = $this->leaveFor($this->employeeIn($owner->currentTeam), '2026-03-09', '2026-03-10');

        $this->actingAs($owner)->get(route('audit-log.index', ['subject' => 'leaverequest']))->assertOk()
            ->assertSee('data-action="leave.created"', false)->assertDontSee('data-action="employee.created"', false);
        $this->assertNotNull($leave);
    }
}
