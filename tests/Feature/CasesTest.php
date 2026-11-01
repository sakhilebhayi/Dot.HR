<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\HrAuditLog;
use App\Models\HrCase;
use App\Models\HrCaseEntry;
use App\Models\HrGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * Employee relations cases (R-32 brief §15): confidential by construction. HR administrators
 * and assigned employee relations handlers work cases; managers, colleagues and the person a
 * grievance is about never reach one; the person a case concerns sees only what HR shared.
 */
class CasesTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    private const SECRET = 'He shouted at me in front of the whole floor';

    /** @return object{owner: User, admin: User, manager: User, staff: User, other: User, employee: Employee, colleague: Employee} */
    private function team(): object
    {
        $owner = $this->owner();
        $admin = $this->memberOf($owner, 'admin');
        $manager = $this->memberOf($owner, 'editor');
        $staff = $this->memberOf($owner, 'viewer');
        $other = $this->memberOf($owner, 'viewer');
        $employee = $this->employeeIn($owner->currentTeam, $staff, ['first_name' => 'Thandi', 'last_name' => 'Mokoena']);
        $colleague = $this->employeeIn($owner->currentTeam, $other, ['first_name' => 'Sipho', 'last_name' => 'Dlamini']);

        return (object) compact('owner', 'admin', 'manager', 'staff', 'other', 'employee', 'colleague');
    }

    private function lodge(object $t, array $extra = []): HrCase
    {
        $this->actingAs($t->staff)->post(route('my-work.cases.lodge'), $extra + [
            'category' => 'treatment', 'title' => 'Shouted at on the floor', 'summary' => self::SECRET, 'about' => 'Sipho Dlamini',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return HrCase::withoutTeamScope(fn () => HrCase::query()->latest('id')->firstOrFail());
    }

    private function discipline(object $t): HrCase
    {
        $this->actingAs($t->owner)->post(route('cases.store'), [
            'type' => 'disciplinary', 'category' => 'misconduct', 'employee_id' => $t->employee->id,
            'title' => 'Cash shortage on 14 October', 'summary' => 'R1 200 short at close, till 3.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return HrCase::withoutTeamScope(fn () => HrCase::query()->latest('id')->firstOrFail());
    }

    public function test_a_grievance_reaches_hr_only_and_is_encrypted_at_rest(): void
    {
        $t = $this->team();
        $case = $this->lodge($t);

        $this->assertSame('employee', $case->source);
        $this->assertStringNotContainsString('shouted', (string) DB::table('hr_cases')->where('id', $case->id)->value('summary'));

        // HR administrators are told, by reference only.
        $notice = DB::table('notifications')->where('notifiable_id', $t->admin->id)->value('data');
        $this->assertNotNull($notice);
        $this->assertStringNotContainsString('Shouted', $notice);
        $this->assertStringNotContainsString('Sipho', $notice);

        $this->actingAs($t->owner)->get(route('cases.show', $case))->assertOk()->assertSee(self::SECRET);
        $this->actingAs($t->admin)->get(route('cases.index'))->assertOk()->assertSee('Shouted at on the floor');

        // Managers, the person it is about and other members reach nothing, not even a 403 that confirms it exists.
        $this->actingAs($t->manager)->get(route('cases.index'))->assertForbidden();
        $this->actingAs($t->manager)->get(route('cases.show', $case))->assertNotFound();
        $this->actingAs($t->other)->get(route('cases.show', $case))->assertNotFound();
        $this->actingAs($t->other)->get(route('my-work.cases.show', $case))->assertNotFound();
        $this->actingAs($t->manager)->get(route('employees.show', $t->employee))->assertDontSee('Shouted at on the floor');

        // The person who raised it follows it from My work.
        $this->actingAs($t->staff)->get(route('my-work.index'))->assertSee('Shouted at on the floor');
        $this->actingAs($t->staff)->get(route('my-work.cases.show', $case))->assertOk()->assertSee(self::SECRET);
    }

    public function test_a_disciplinary_case_stays_out_of_sight_until_hr_shares_and_follows_a_fair_process(): void
    {
        $t = $this->team();
        $case = $this->discipline($t);

        $this->actingAs($t->staff)->get(route('my-work.cases.show', $case))->assertNotFound();
        $this->actingAs($t->staff)->get(route('my-work.index'))->assertDontSee($case->reference());

        $this->actingAs($t->owner)->post(route('cases.entries.store', $case), ['kind' => 'statement', 'body' => 'Supervisor statement: counted twice.'])->assertRedirect();
        $this->actingAs($t->owner)->post(route('cases.investigate', $case))->assertRedirect();
        $this->actingAs($t->owner)->post(route('cases.hearing', $case), [
            'hearing_at' => now()->addDays(5)->setTime(10, 0)->toDateTimeString(), 'hearing_place' => 'Boardroom',
            'hearing_chair' => 'Lerato Nkosi', 'points' => 'That you removed R1 200 from till 3 on 14 October.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        // Now the notice reaches them, with what Schedule 8 says they are entitled to; the internal statement does not.
        $page = $this->actingAs($t->staff)->get(route('my-work.cases.show', $case))->assertOk();
        $page->assertSee('disciplinary hearing')->assertSee('shop steward')->assertSee('removed R1 200')->assertDontSee('Supervisor statement');
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $t->staff->id]);

        $notice = HrCaseEntry::withoutTeamScope(fn () => HrCaseEntry::query()->where('kind', 'notice')->sole());
        $this->actingAs($t->staff)->post(route('my-work.cases.received', $notice))->assertRedirect();
        $this->assertNotNull($notice->fresh()->received_at);
        $this->actingAs($t->staff)->post(route('my-work.cases.reply', $case), ['body' => 'I was on leave that afternoon.'])->assertRedirect();

        $this->actingAs($t->owner)->post(route('cases.decide', $case), ['outcome' => 'written_warning', 'reasons' => 'The till log shows your login at 15:02.', 'share' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $case->refresh();
        $this->assertSame('decided', $case->status);
        $this->assertSame(now()->addMonthsNoOverflow(6)->toDateString(), $case->warning_expires_on->toDateString());
        $this->assertTrue($case->warningActive());
        $this->actingAs($t->owner)->get(route('employees.show', $t->employee))->assertSee('Warning until');

        // Deciding twice is refused; the person appeals and the appeal can set the warning aside.
        $this->actingAs($t->owner)->post(route('cases.decide', $case), ['outcome' => 'dismissal', 'reasons' => 'A second decision.'])->assertSessionHasErrors('case');
        $this->actingAs($t->staff)->post(route('my-work.cases.appeal', $case), ['grounds' => 'The log was shared by the whole shift.'])->assertRedirect();
        $this->assertSame('appealed', $case->fresh()->status);
        $this->actingAs($t->owner)->post(route('cases.appeal-decision', $case), ['appeal_outcome' => 'upheld', 'reasons' => 'Shared logins make the log unreliable.'])->assertRedirect();
        $this->assertFalse($case->fresh()->warningActive());
        $this->actingAs($t->staff)->get(route('my-work.cases.show', $case))->assertSee('The warning no longer counts');
    }

    public function test_a_dismissal_tells_the_person_about_the_ccma(): void
    {
        $t = $this->team();
        $case = $this->discipline($t);
        $this->actingAs($t->owner)->post(route('cases.decide', $case), ['outcome' => 'dismissal', 'reasons' => 'Gross dishonesty, proven at the hearing.', 'share' => 1])->assertRedirect();

        $this->actingAs($t->staff)->get(route('my-work.cases.show', $case))->assertOk()->assertSee('CCMA')->assertSee('within 30 days');
        $this->assertNull($case->fresh()->warning_expires_on);
    }

    public function test_a_grievance_about_hr_reaches_only_the_owner(): void
    {
        $t = $this->team();
        $case = $this->lodge($t, ['owner_only' => 1]);

        $this->assertTrue($case->restricted);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $t->owner->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $t->admin->id]);
        $this->actingAs($t->admin)->get(route('cases.show', $case))->assertNotFound();
        $this->actingAs($t->admin)->get(route('cases.index'))->assertDontSee('Shouted at on the floor');
        $this->actingAs($t->owner)->get(route('cases.show', $case))->assertOk();

        // The owner hands it to the admin: now they may open it.
        $this->actingAs($t->owner)->post(route('cases.assign', $case), ['handler_user_id' => $t->admin->id, 'restricted' => 1])->assertRedirect();
        $this->actingAs($t->admin)->get(route('cases.show', $case))->assertOk();
    }

    public function test_an_employee_relations_handler_sees_only_their_own_cases(): void
    {
        $t = $this->team();
        $handler = $this->memberOf($t->owner, 'viewer');
        HrGrant::create(['team_id' => $t->owner->currentTeam->id, 'user_id' => $handler->id, 'capability' => HrGrant::EMPLOYEE_RELATIONS, 'granted_by' => $t->owner->id]);

        $theirs = $this->discipline($t);
        $this->actingAs($handler)->get(route('cases.index'))->assertOk()->assertDontSee('Cash shortage');
        $this->actingAs($handler)->get(route('cases.show', $theirs))->assertNotFound();

        $this->actingAs($t->owner)->post(route('cases.assign', $theirs), ['handler_user_id' => $handler->id])->assertRedirect();
        $this->actingAs($handler)->get(route('cases.show', $theirs))->assertOk();
        $this->actingAs($handler)->post(route('cases.assign', $theirs), ['handler_user_id' => $handler->id])->assertForbidden();

        // Someone without the grant cannot be made a handler.
        $this->actingAs($t->owner)->post(route('cases.assign', $theirs), ['handler_user_id' => $t->manager->id])->assertSessionHasErrors('handler_user_id');
    }

    public function test_a_work_injury_shows_when_it_must_be_reported(): void
    {
        $t = $this->team();
        $this->actingAs($t->owner)->post(route('cases.store'), [
            'type' => 'incident', 'category' => 'injury', 'employee_id' => $t->employee->id,
            'title' => 'Cut hand at the slicer', 'summary' => 'Deep cut, first aid given, taken to clinic.', 'occurred_on' => now()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $case = HrCase::withoutTeamScope(fn () => HrCase::query()->latest('id')->firstOrFail());

        $this->actingAs($t->owner)->get(route('cases.index'))->assertSee('not yet marked as reported');
        $this->actingAs($t->owner)->get(route('cases.show', $case))->assertSee('Compensation Fund by '.now()->addDays(7)->format('j F Y'));

        $this->actingAs($t->owner)->post(route('cases.reported', $case), ['reported_on' => now()->toDateString()])->assertRedirect();
        $this->assertNull($case->fresh()->externalReportDue());
        $this->actingAs($t->owner)->post(route('cases.hearing', $case), ['hearing_at' => now()->addDays(3)->toDateTimeString(), 'hearing_place' => 'Office', 'points' => 'Not applicable here.'])->assertSessionHasErrors('hearing_at');
    }

    public function test_the_audit_trail_names_steps_never_what_was_said(): void
    {
        $t = $this->team();
        $case = $this->lodge($t);
        $this->actingAs($t->owner)->get(route('cases.show', $case))->assertOk();
        $this->actingAs($t->owner)->post(route('cases.entries.store', $case), ['kind' => 'note', 'body' => 'Spoke to two witnesses privately.'])->assertRedirect();

        $logs = HrAuditLog::withoutTeamScope(fn () => HrAuditLog::query()->where('subject_type', HrCase::class)->get());
        $this->assertEqualsCanonicalizing(['case.opened', 'case.viewed', 'case.entry_added'], $logs->pluck('action')->all());
        $this->assertStringNotContainsString('witnesses', $logs->toJson());
        $this->assertStringNotContainsString('shouted', strtolower($logs->toJson()));
    }

    public function test_exports_hold_what_each_side_may_see(): void
    {
        $t = $this->team();
        $case = $this->discipline($t);
        $this->actingAs($t->owner)->post(route('cases.entries.store', $case), ['kind' => 'note', 'body' => 'Internal: check the CCTV.'])->assertRedirect();
        $this->actingAs($t->owner)->post(route('cases.entries.store', $case), ['kind' => 'update', 'body' => 'We are looking into the till shortage.', 'share' => 1])->assertRedirect();

        $mine = $this->actingAs($t->staff)->get(route('my-work.data'))->assertOk()->json();
        $this->assertSame($case->reference(), $mine['hr_cases'][0]['reference']);
        $this->assertNull($mine['hr_cases'][0]['account']);
        $this->assertSame(['We are looking into the till shortage.'], array_column($mine['hr_cases'][0]['record'], 'text'));

        $hr = $this->actingAs($t->owner)->get(route('employees.personal-data', $t->employee))->assertOk()->json();
        $this->assertCount(2, $hr['hr_cases'][0]['record']);
        $this->assertSame(0, $hr['restricted_hr_cases_not_included']);
    }

    public function test_closed_cases_leave_after_the_retention_period(): void
    {
        $t = $this->team();
        $old = $this->discipline($t);
        $this->actingAs($t->owner)->post(route('cases.withdraw', $old), ['reason' => 'Raised in error.'])->assertRedirect();
        $old->refresh()->forceFill(['closed_at' => now()->subDays(365 * 5 + 1)])->save();
        $open = $this->discipline($t);

        $this->artisan('hr:retention')->assertSuccessful();

        $this->assertDatabaseMissing('hr_cases', ['id' => $old->id]);
        $this->assertDatabaseMissing('hr_case_entries', ['hr_case_id' => $old->id]);
        $this->assertDatabaseHas('hr_cases', ['id' => $open->id]);
    }
}
