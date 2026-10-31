<?php

namespace Tests\Feature;

use App\Models\ChecklistTemplate;
use App\Models\Employee;
use App\Models\HrAuditLog;
use App\Models\HrRequest;
use App\Models\LifecycleChecklist;
use App\Models\LifecycleTask;
use App\Models\User;
use App\Services\Hr\Lifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * R-32 Phase 5: onboarding, self-service, HR requests and offboarding (brief §7, §15 and the
 * §23 "Employee management" scenarios).
 */
class LifecycleTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: Employee} owner, linked staff member, their record */
    private function staffSetup(): array
    {
        $owner = $this->owner();
        $staff = $this->memberOf($owner, 'viewer');
        $employee = $this->employeeIn($owner->currentTeam, $staff, ['first_name' => 'Lerato', 'last_name' => 'Mokgosi']);

        return [$owner, $staff, $employee];
    }

    private function checklistFor(Employee $employee, string $process): LifecycleChecklist
    {
        return LifecycleChecklist::withoutTeamScope(fn () => LifecycleChecklist::query()
            ->where('employee_id', $employee->id)->where('process', $process)->latest('id')->firstOrFail());
    }

    public function test_hr_starts_onboarding_with_the_default_checklist_once_and_the_person_is_told(): void
    {
        [$owner, $staff, $employee] = $this->staffSetup();

        $this->actingAs($owner)->post(route('lifecycle.start', [$employee, 'onboarding']))->assertRedirect();
        $this->actingAs($owner)->post(route('lifecycle.start', [$employee, 'onboarding']))->assertRedirect();

        $this->assertSame(1, LifecycleChecklist::withoutTeamScope(fn () => LifecycleChecklist::query()->count()), 'Starting twice reuses the open checklist');
        $checklist = $this->checklistFor($employee, 'onboarding');
        $this->assertSame(count(ChecklistTemplate::DEFAULTS['onboarding']), LifecycleTask::withoutTeamScope(fn () => LifecycleTask::query()->where('lifecycle_checklist_id', $checklist->id)->count()));
        $this->assertSame(1, $staff->fresh()->notifications()->count());
    }

    public function test_the_person_completes_their_own_steps_and_acknowledges_a_policy_with_a_record(): void
    {
        [$owner, $staff, $employee] = $this->staffSetup();
        $this->actingAs($owner)->post(route('lifecycle.start', [$employee, 'onboarding']));
        $checklist = $this->checklistFor($employee, 'onboarding');

        $tasks = LifecycleTask::withoutTeamScope(fn () => LifecycleTask::query()->where('lifecycle_checklist_id', $checklist->id)->get());
        $mine = $tasks->where('owner', 'employee')->where('kind', 'task')->first();
        $ack = $tasks->where('kind', 'acknowledge')->first();
        $hrTask = $tasks->where('owner', 'hr')->first();

        $this->actingAs($staff)->get(route('my-work.index'))->assertOk()->assertSee($mine->title)->assertSee($ack->title)->assertDontSee($hrTask->title);

        $this->actingAs($staff)->post(route('my-work.tasks.complete', $mine))->assertRedirect();
        $this->assertNotNull($mine->fresh()->completed_at);

        // An acknowledgement needs the explicit tick.
        $this->actingAs($staff)->post(route('my-work.tasks.complete', $ack))->assertSessionHasErrors('confirm');
        $this->actingAs($staff)->post(route('my-work.tasks.complete', $ack), ['confirm' => '1'])->assertRedirect();
        $this->assertSame($staff->id, $ack->fresh()->completed_by);
        $this->assertContains('policy.acknowledged', HrAuditLog::withoutTeamScope(fn () => HrAuditLog::query()->pluck('action')->all()));

        // HR's tasks are not the person's to tick, and the person's are not HR's.
        $this->actingAs($staff)->post(route('my-work.tasks.complete', $hrTask))->assertNotFound();
        $other = $tasks->where('owner', 'employee')->where('kind', 'task')->skip(1)->first();
        $this->actingAs($owner)->post(route('lifecycle.tasks.toggle', $other))->assertForbidden();
        $this->actingAs($owner)->post(route('lifecycle.tasks.toggle', $hrTask))->assertRedirect();
        $this->assertNotNull($hrTask->fresh()->completed_at);
    }

    public function test_hr_may_complete_steps_for_someone_without_an_account(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam, null, ['first_name' => 'Sipho']);
        $this->actingAs($owner)->post(route('lifecycle.start', [$employee, 'onboarding']));

        $theirs = LifecycleTask::withoutTeamScope(fn () => LifecycleTask::query()->where('owner', 'employee')->firstOrFail());
        $this->actingAs($owner)->post(route('lifecycle.tasks.toggle', $theirs))->assertRedirect();
        $this->assertNotNull($theirs->fresh()->completed_at);
    }

    public function test_a_person_updates_only_their_phone_and_emergency_contact_encrypted_and_audited(): void
    {
        [, $staff, $employee] = $this->staffSetup();

        $this->actingAs($staff)->put(route('my-work.details'), [
            'phone' => '+27 82 555 0101',
            'emergency_contact_name' => 'Mpho Mokgosi (sister)',
            'emergency_contact_phone' => '082 555 0202',
            'employment_type' => 'contractor', // not theirs to change: ignored
        ])->assertRedirect();

        $fresh = Employee::withoutTeamScope(fn () => Employee::query()->findOrFail($employee->id));
        $this->assertSame('Mpho Mokgosi (sister)', $fresh->emergency_contact_name);
        $this->assertSame($employee->employment_type, $fresh->employment_type);

        $raw = DB::table('employees')->where('id', $employee->id)->value('emergency_contact_name');
        $this->assertStringNotContainsString('Mpho', (string) $raw, 'Stored encrypted, not in plain text');

        $log = HrAuditLog::withoutTeamScope(fn () => HrAuditLog::query()->where('action', 'employee.updated')->latest('id')->firstOrFail());
        $this->assertContains('emergency_contact_name', $log->fields);
        $this->assertStringNotContainsString('Mpho', json_encode($log->changes ?? []), 'Values never reach the audit log');
    }

    public function test_hr_requests_reach_hr_admins_only_and_answers_reach_the_person(): void
    {
        [$owner, $staff, $employee] = $this->staffSetup();
        $manager = $this->memberOf($owner, 'editor');

        $this->actingAs($staff)->post(route('my-work.requests.store'), [
            'category' => 'letter', 'subject' => 'Employment confirmation', 'body' => 'For my bond application at the bank.',
        ])->assertRedirect();

        $hrRequest = HrRequest::withoutTeamScope(fn () => HrRequest::query()->firstOrFail());
        $this->assertStringNotContainsString('bond application', (string) DB::table('hr_requests')->value('body'), 'Encrypted at rest');

        $this->actingAs($manager)->get(route('requests.index'))->assertForbidden();
        $this->actingAs($manager)->get(route('requests.show', $hrRequest))->assertForbidden();
        $this->actingAs($owner)->get(route('requests.show', $hrRequest))->assertOk()->assertSee('For my bond application at the bank.');

        $this->actingAs($owner)->post(route('requests.answer', $hrRequest), ['response' => 'Attached to your email by Friday.'])->assertRedirect();
        $this->actingAs($staff)->get(route('my-work.requests.show', $hrRequest))->assertOk()->assertSee('Attached to your email by Friday.');

        // Another employee cannot open it.
        $colleague = $this->memberOf($owner, 'viewer');
        $this->employeeIn($owner->currentTeam, $colleague);
        $this->actingAs($colleague)->get(route('my-work.requests.show', $hrRequest))->assertNotFound();
    }

    public function test_offboarding_records_the_departure_only_when_hr_finishes_it(): void
    {
        [$owner, , $employee] = $this->staffSetup();

        $this->actingAs($owner)->post(route('lifecycle.start', [$employee, 'offboarding']), [])->assertSessionHasErrors(['last_day', 'reason']);

        $this->actingAs($owner)->post(route('lifecycle.start', [$employee, 'offboarding']), [
            'last_day' => now()->addWeeks(2)->toDateString(), 'reason' => 'resignation',
        ])->assertRedirect();

        $checklist = $this->checklistFor($employee, 'offboarding');
        $this->assertSame('active', Employee::withoutTeamScope(fn () => Employee::query()->find($employee->id)->status), 'Starting changes nothing on the record');

        $this->actingAs($owner)->post(route('lifecycle.finish', $checklist))->assertRedirect();

        $fresh = Employee::withoutTeamScope(fn () => Employee::query()->find($employee->id));
        $this->assertSame('terminated', $fresh->status);
        $this->assertSame(now()->addWeeks(2)->toDateString(), $fresh->end_date->toDateString());
        $this->assertSame('done', $checklist->fresh()->status);
    }

    public function test_cancelling_changes_nothing_on_the_record(): void
    {
        [$owner, , $employee] = $this->staffSetup();
        $this->actingAs($owner)->post(route('lifecycle.start', [$employee, 'offboarding']), ['last_day' => now()->addWeek()->toDateString(), 'reason' => 'other']);
        $checklist = $this->checklistFor($employee, 'offboarding');

        $this->actingAs($owner)->post(route('lifecycle.cancel', $checklist))->assertRedirect();

        $this->assertSame('active', Employee::withoutTeamScope(fn () => Employee::query()->find($employee->id)->status));
        $this->assertSame('cancelled', $checklist->fresh()->status);
    }

    public function test_a_business_writes_its_own_checklist_and_new_checklists_use_it(): void
    {
        [$owner, , $employee] = $this->staffSetup();

        $this->actingAs($owner)->put(route('lifecycle.templates.update', 'onboarding'), [
            'employee_tasks' => "Collect your PPE from the store\nAttend the site induction",
            'acknowledgements' => 'Site safety rules — Hard hats and boots at all times on the yard.',
            'hr_tasks' => 'Book the induction',
        ])->assertRedirect();

        $this->actingAs($owner)->post(route('lifecycle.start', [$employee, 'onboarding']));
        $tasks = LifecycleTask::withoutTeamScope(fn () => LifecycleTask::query()->orderBy('position')->get());

        $this->assertSame(['Collect your PPE from the store', 'Attend the site induction', 'Site safety rules', 'Book the induction'], $tasks->pluck('title')->all());
        $this->assertSame('Hard hats and boots at all times on the yard.', $tasks->firstWhere('kind', 'acknowledge')->body);
    }

    public function test_lifecycle_and_requests_are_invisible_across_businesses_and_to_non_admins(): void
    {
        [$owner, $staff, $employee] = $this->staffSetup();
        $this->actingAs($owner)->post(route('lifecycle.start', [$employee, 'onboarding']));
        $checklist = $this->checklistFor($employee, 'onboarding');

        $otherOwner = $this->owner();
        $this->actingAs($otherOwner)->get(route('lifecycle.show', $checklist))->assertNotFound();
        $this->actingAs($otherOwner)->post(route('lifecycle.finish', $checklist))->assertNotFound();

        $this->actingAs($staff)->get(route('lifecycle.index'))->assertForbidden();
        $this->actingAs($staff)->post(route('lifecycle.start', [$employee, 'offboarding']), ['last_day' => now()->toDateString(), 'reason' => 'other'])->assertForbidden();

        // Someone with no employee record here has no "My work".
        $noRecord = $this->memberOf($owner, 'viewer');
        $this->actingAs($noRecord)->get(route('my-work.index'))->assertNotFound();
    }

    public function test_starting_onboarding_twice_in_code_reuses_the_open_checklist(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam);

        app(Lifecycle::class)->start($employee, 'onboarding', $owner->id);
        app(Lifecycle::class)->start($employee, 'onboarding', $owner->id);

        $this->assertSame(1, LifecycleChecklist::withoutTeamScope(fn () => LifecycleChecklist::query()->where('employee_id', $employee->id)->count()));
    }
}
