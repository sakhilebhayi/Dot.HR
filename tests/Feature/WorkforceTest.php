<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\EmployeeCertification;
use App\Models\HrAuditLog;
use App\Models\HrGrant;
use App\Models\Position;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * R-32 Phase 6a, brief §23 "Workforce management": a manager creates a shift, employees are
 * assigned, attendance is recorded, timesheets are submitted and approvals are processed —
 * plus certifications and readiness.
 */
class WorkforceTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function supervisorOf(User $owner): User
    {
        $supervisor = $this->memberOf($owner, 'editor');
        HrGrant::query()->create(['team_id' => $owner->currentTeam->id, 'user_id' => $supervisor->id, 'capability' => HrGrant::SUPERVISOR]);

        return $supervisor;
    }

    private function shiftPayload(array $overrides = []): array
    {
        return array_merge([
            'date' => '2026-11-04', 'start' => '07:00', 'end' => '16:00', 'break_minutes' => 60,
            'title' => 'Morning receiving', 'location' => 'Warehouse',
        ], $overrides);
    }

    public function test_a_supervisor_builds_a_shift_assigns_people_and_a_night_shift_crosses_midnight(): void
    {
        $owner = $this->owner();
        $supervisor = $this->supervisorOf($owner);
        $a = $this->employeeIn($owner->currentTeam, null, ['first_name' => 'Thabo']);

        $this->actingAs($supervisor)->post(route('schedule.store'), $this->shiftPayload(['employee_ids' => [$a->id]]))->assertRedirect();
        $shift = Shift::query()->firstOrFail();
        $this->assertSame(480, $shift->paidMinutes());
        $this->assertTrue($shift->employees->contains($a));

        $this->actingAs($supervisor)->post(route('schedule.store'), $this->shiftPayload(['date' => '2026-11-05', 'start' => '22:00', 'end' => '06:00', 'break_minutes' => 30]));
        $night = Shift::query()->latest('id')->firstOrFail();
        $this->assertSame('2026-11-06 06:00', $night->ends_at->format('Y-m-d H:i'));

        $this->actingAs($supervisor)->get(route('schedule.index', ['week' => '2026-11-04']))->assertOk()->assertSee('Morning receiving')->assertSee('Thabo');
    }

    public function test_overlapping_shifts_and_shifts_during_approved_leave_are_refused_with_a_reason(): void
    {
        $owner = $this->owner();
        $a = $this->employeeIn($owner->currentTeam, null, ['first_name' => 'Thabo', 'last_name' => 'Dlamini']);

        $this->actingAs($owner)->post(route('schedule.store'), $this->shiftPayload(['employee_ids' => [$a->id]]));
        $this->actingAs($owner)->post(route('schedule.store'), $this->shiftPayload(['start' => '12:00', 'end' => '20:00', 'employee_ids' => [$a->id]]))
            ->assertSessionHas('error', fn ($msg) => str_contains($msg, 'already on a shift'));

        $this->leaveFor($a, '2026-11-10', '2026-11-12', ['status' => 'approved']);
        $this->actingAs($owner)->post(route('schedule.store'), $this->shiftPayload(['date' => '2026-11-11', 'employee_ids' => [$a->id]]))
            ->assertSessionHas('error', fn ($msg) => str_contains($msg, 'approved leave'));

        $this->assertSame(1, Shift::query()->whereHas('employees')->count());
    }

    public function test_the_whole_week_clock_submit_approve_and_lock(): void
    {
        Carbon::setTestNow('2026-11-02 07:00:00'); // a Monday
        $owner = $this->owner();
        $owner->currentTeam->forceFill(['weekly_ordinary_hours' => 4])->save();
        $staff = $this->memberOf($owner, 'viewer');
        $this->employeeIn($owner->currentTeam, $staff);

        $this->actingAs($staff)->post(route('my-work.clock', 'in'))->assertSessionHas('status');
        $this->actingAs($staff)->post(route('my-work.clock', 'in'))->assertSessionHas('error', 'You are already clocked in.');

        Carbon::setTestNow('2026-11-02 12:30:00');
        $this->actingAs($staff)->post(route('my-work.clock', 'out'))->assertSessionHas('status');

        // An open stretch blocks submitting; a closed week submits with the overtime flag.
        $this->actingAs($staff)->post(route('my-work.timesheet.submit'), ['week_start' => '2026-11-02'])->assertSessionHas('status');
        $sheet = Timesheet::query()->firstOrFail();
        $this->assertSame(330, $sheet->worked_minutes);
        $this->assertSame(90, $sheet->overtime_minutes);
        $this->assertSame('submitted', $sheet->status);

        $this->actingAs($owner)->post(route('timesheets.decide', [$sheet, 'approve']))->assertSessionHas('status');
        $this->assertSame('approved', $sheet->fresh()->status);

        // The approved week is locked: no clocking in it, no corrections.
        Carbon::setTestNow('2026-11-03 07:00:00');
        $this->actingAs($staff)->post(route('my-work.clock', 'in'))->assertSessionHas('error', fn ($m) => str_contains($m, 'locked'));

        $record = AttendanceRecord::query()->firstOrFail();
        $this->actingAs($owner)->put(route('attendance.update', $record), [
            'clock_in_at' => '2026-11-02 06:00', 'clock_out_at' => '2026-11-02 12:30',
        ])->assertSessionHas('error');
        $this->assertSame('07:00', $record->fresh()->clock_in_at->format('H:i'));
    }

    public function test_clocking_reads_the_business_wall_clock_not_utc(): void
    {
        config(['hr.local_timezone' => 'Africa/Johannesburg']);
        $owner = $this->owner();
        $staff = $this->memberOf($owner, 'viewer');
        $person = $this->employeeIn($owner->currentTeam, $staff);

        $this->actingAs($owner)->post(route('schedule.store'), $this->shiftPayload(['date' => '2026-11-04', 'start' => '08:00', 'end' => '17:00', 'employee_ids' => [$person->id]]));

        // 06:05 UTC is 08:05 in Johannesburg: the clock-in lands inside the 08:00 shift.
        Carbon::setTestNow(Carbon::parse('2026-11-04 06:05:00', 'UTC'));
        $this->actingAs($staff)->post(route('my-work.clock', 'in'))->assertSessionHas('status');

        $record = AttendanceRecord::query()->firstOrFail();
        $this->assertSame('2026-11-04 08:05', $record->clock_in_at->format('Y-m-d H:i'));
        $this->assertSame(Shift::query()->value('id'), $record->shift_id);
    }

    public function test_a_supervisor_records_a_missed_stretch_audited_and_cannot_decide_their_own_week(): void
    {
        Carbon::setTestNow('2026-11-04 18:00:00');
        $owner = $this->owner();
        $supervisor = $this->supervisorOf($owner);
        $mine = $this->employeeIn($owner->currentTeam, $supervisor);
        $other = $this->employeeIn($owner->currentTeam, null, ['first_name' => 'Kagiso']);

        $this->actingAs($supervisor)->post(route('attendance.store'), [
            'employee_id' => $other->id, 'clock_in_at' => '2026-11-04 08:00', 'clock_out_at' => '2026-11-04 17:00',
            'break_minutes' => 60, 'note' => 'Forgot to clock in',
        ])->assertSessionHas('status');

        $record = AttendanceRecord::query()->firstOrFail();
        $this->assertSame('supervisor', $record->source);
        $this->assertSame(480, $record->workedMinutes());
        $this->assertContains('attendance.recorded', HrAuditLog::withoutTeamScope(fn () => HrAuditLog::query()->pluck('action')->all()));

        $this->actingAs($supervisor)->post(route('attendance.store'), [
            'employee_id' => $other->id, 'clock_in_at' => '2026-11-04 17:00', 'clock_out_at' => '2026-11-04 08:00',
        ])->assertSessionHas('error', 'The end must be after the start.');

        // Their own week goes to someone else.
        $this->actingAs($supervisor)->post(route('attendance.store'), [
            'employee_id' => $mine->id, 'clock_in_at' => '2026-11-04 08:00', 'clock_out_at' => '2026-11-04 12:00',
        ]);
        $this->actingAs($supervisor)->post(route('my-work.timesheet.submit'), ['week_start' => '2026-11-02']);
        $sheet = Timesheet::query()->where('employee_id', $mine->id)->firstOrFail();
        $this->actingAs($supervisor)->post(route('timesheets.decide', [$sheet, 'approve']))->assertSessionHas('error');
        $this->assertSame('submitted', $sheet->fresh()->status);
    }

    public function test_workforce_pages_need_the_grant_and_are_isolated_between_businesses(): void
    {
        $owner = $this->owner();
        $member = $this->memberOf($owner, 'viewer');
        $manager = $this->memberOf($owner, 'editor');
        $this->actingAs($owner)->post(route('schedule.store'), $this->shiftPayload());
        $shift = Shift::query()->firstOrFail();

        $this->actingAs($member)->get(route('schedule.index'))->assertForbidden();
        $this->actingAs($manager)->get(route('schedule.index'))->assertOk();
        $this->actingAs($manager)->post(route('schedule.store'), $this->shiftPayload())->assertForbidden();
        $this->actingAs($manager)->get(route('attendance.index'))->assertForbidden();
        $this->actingAs($manager)->get(route('timesheets.index'))->assertForbidden();

        $stranger = $this->owner();
        $this->actingAs($stranger)->delete(route('schedule.destroy', $shift))->assertNotFound();
        $this->actingAs($stranger)->get(route('schedule.index', ['week' => '2026-11-04']))->assertOk()->assertDontSee('Morning receiving');
    }

    public function test_readiness_shows_missing_expired_and_soon_and_reminders_go_out_once(): void
    {
        Carbon::setTestNow('2026-11-01 08:00:00');
        $owner = $this->owner();
        $staff = $this->memberOf($owner, 'viewer');
        $position = new Position;
        $position->forceFill(['team_id' => $owner->currentTeam->id, 'title' => 'Forklift operator'])->save();
        $driver = $this->employeeIn($owner->currentTeam, $staff, ['first_name' => 'Sipho', 'position_id' => $position->id]);

        $this->actingAs($owner)->post(route('requirements.store', $position), ['name' => 'Forklift licence']);
        $this->actingAs($owner)->post(route('requirements.store', $position), ['name' => 'Medical certificate']);
        $this->actingAs($owner)->post(route('requirements.store', $position), ['name' => 'Site induction']);

        $this->actingAs($owner)->post(route('certifications.store'), ['employee_id' => $driver->id, 'name' => 'forklift LICENCE', 'expires_on' => '2026-10-01']);
        $this->actingAs($owner)->post(route('certifications.store'), ['employee_id' => $driver->id, 'name' => 'Medical certificate', 'expires_on' => '2026-11-20', 'verified' => '1']);

        $this->actingAs($owner)->get(route('certifications.index'))->assertOk()
            ->assertSee('Forklift licence — expired')
            ->assertSee('Site induction — missing')
            ->assertSee('Medical certificate — lapses soon');

        $this->artisan('hr:certification-reminders')->assertExitCode(0);
        $this->artisan('hr:certification-reminders')->assertExitCode(0);

        $this->assertSame(1, $owner->fresh()->notifications()->count(), 'Reminded once, about the one lapsing soon');
        $this->assertSame(1, $staff->fresh()->notifications()->count());
        $this->assertNotNull(EmployeeCertification::withoutTeamScope(fn () => EmployeeCertification::query()->where('name', 'Medical certificate')->value('reminded_at')));

        // The person sees their own certificates in My work.
        $this->actingAs($staff)->get(route('my-work.index'))->assertSee('Medical certificate')->assertSee('renew');
    }

    public function test_the_person_sees_their_next_shift_in_my_work(): void
    {
        Carbon::setTestNow('2026-11-03 08:00:00');
        $owner = $this->owner();
        $staff = $this->memberOf($owner, 'viewer');
        $me = $this->employeeIn($owner->currentTeam, $staff);

        $this->actingAs($owner)->post(route('schedule.store'), $this->shiftPayload(['employee_ids' => [$me->id]]));

        $this->actingAs($staff)->get(route('my-work.index'))->assertOk()
            ->assertSee('Your next shift is')
            ->assertSee('07:00–16:00');
    }
}
