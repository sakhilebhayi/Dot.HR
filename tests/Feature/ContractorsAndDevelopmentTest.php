<?php

namespace Tests\Feature;

use App\Models\ContractorInvoice;
use App\Models\EmployeeCertification;
use App\Models\Engagement;
use App\Models\EngagementMilestone;
use App\Models\Goal;
use App\Models\HrAuditLog;
use App\Models\HrGrant;
use App\Models\Position;
use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\Shift;
use App\Models\TrainingRecord;
use App\Models\User;
use App\Services\Hr\Readiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * R-32 Phase 6b, brief §10 and §12: contractor engagements (milestones, invoices, renewal,
 * ending, classification signals), written reviews without scores, goals, and training that
 * closes readiness gaps.
 */
class ContractorsAndDevelopmentTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function engagementPayload(array $overrides = []): array
    {
        return array_merge([
            'kind' => 'contractor', 'title' => 'Conveyor maintenance', 'scope' => 'Monthly service of conveyors 1 to 4.',
            'rate_basis' => 'daily', 'rate_amount' => '3500', 'currency' => 'ZAR',
            'starts_on' => '2026-11-02', 'ends_on' => '2027-01-31',
        ], $overrides);
    }

    private function grant(User $owner, User $user, string $capability): void
    {
        HrGrant::query()->create(['team_id' => $owner->currentTeam->id, 'user_id' => $user->id, 'capability' => $capability]);
    }

    public function test_an_admin_engages_a_new_contractor_and_employees_cannot_be_engaged(): void
    {
        $owner = $this->owner();
        $staff = $this->employeeIn($owner->currentTeam, null, ['first_name' => 'Thabo', 'employment_type' => 'full_time']);

        $this->actingAs($owner)->post(route('contractors.store'), $this->engagementPayload(['first_name' => 'Naledi', 'last_name' => 'Khumalo']))
            ->assertRedirect();
        $engagement = Engagement::query()->with('employee')->firstOrFail();
        $this->assertSame('contractor', $engagement->employee->employment_type);
        $this->assertSame('Naledi Khumalo', $engagement->employee->fullName());
        $this->assertSame('ZAR 3,500.00 per day', $engagement->rate());

        $this->actingAs($owner)->post(route('contractors.store'), $this->engagementPayload(['employee_id' => $staff->id]))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'recorded as an employee'));
        $this->assertSame(1, Engagement::query()->count());

        $this->actingAs($owner)->get(route('contractors.show', $engagement))->assertOk()
            ->assertSee('Conveyor maintenance')->assertSee('section 200A', false);
    }

    public function test_contractor_pages_are_for_hr_administrators_only_and_isolated(): void
    {
        $owner = $this->owner();
        $supervisor = $this->memberOf($owner, 'editor');
        $this->grant($owner, $supervisor, HrGrant::SUPERVISOR);
        $this->actingAs($owner)->post(route('contractors.store'), $this->engagementPayload(['first_name' => 'Naledi', 'last_name' => 'Khumalo']));
        $engagement = Engagement::query()->firstOrFail();

        $this->actingAs($supervisor)->get(route('contractors.index'))->assertForbidden();
        $this->actingAs($supervisor)->get(route('contractors.show', $engagement))->assertForbidden();

        $stranger = $this->owner();
        $this->actingAs($stranger)->get(route('contractors.show', $engagement))->assertNotFound();
        $this->actingAs($stranger)->get(route('contractors.index'))->assertOk()->assertDontSee('Conveyor maintenance');
    }

    public function test_milestones_are_delivered_by_the_contractor_and_approved_by_hr(): void
    {
        $owner = $this->owner();
        $contractorUser = $this->memberOf($owner, 'viewer');
        $person = $this->employeeIn($owner->currentTeam, $contractorUser, ['employment_type' => 'contractor']);
        $otherUser = $this->memberOf($owner, 'viewer');
        $this->employeeIn($owner->currentTeam, $otherUser);

        $this->actingAs($owner)->post(route('contractors.store'), $this->engagementPayload(['employee_id' => $person->id]));
        $engagement = Engagement::query()->firstOrFail();
        $this->actingAs($owner)->post(route('contractors.milestones.store', $engagement), ['title' => 'Conveyor 1 serviced', 'due_on' => '2026-11-10']);
        $milestone = EngagementMilestone::query()->firstOrFail();

        $this->actingAs($otherUser)->post(route('my-work.milestones.deliver', $milestone))->assertNotFound();
        $this->actingAs($contractorUser)->get(route('my-work.index'))->assertOk()->assertSee('Conveyor 1 serviced')->assertSee('Mark as delivered');
        $this->actingAs($contractorUser)->post(route('my-work.milestones.deliver', $milestone))->assertSessionHas('status');
        $this->assertSame('submitted', $milestone->fresh()->status);

        $this->actingAs($owner)->post(route('milestones.decide', [$milestone, 'reject']))->assertSessionHasErrors('note');
        $this->actingAs($owner)->post(route('milestones.decide', [$milestone, 'reject']), ['note' => 'Belt tension not logged'])->assertSessionHas('status');
        $this->assertSame('rejected', $milestone->fresh()->status);

        $this->actingAs($contractorUser)->post(route('my-work.milestones.deliver', $milestone));
        $this->actingAs($owner)->post(route('milestones.decide', [$milestone, 'approve']))->assertSessionHas('status');
        $this->assertSame('approved', $milestone->fresh()->status);
        $this->assertTrue($contractorUser->notifications()->exists());
    }

    public function test_invoices_are_records_that_move_only_forward_and_references_are_unique(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post(route('contractors.store'), $this->engagementPayload(['first_name' => 'Naledi', 'last_name' => 'Khumalo']));
        $engagement = Engagement::query()->firstOrFail();
        $today = now()->toDateString();

        $this->actingAs($owner)->post(route('contractors.invoices.store', $engagement), ['reference' => 'INV-001', 'amount' => '17500', 'received_on' => $today])->assertSessionHas('status');
        $this->actingAs($owner)->post(route('contractors.invoices.store', $engagement), ['reference' => 'INV-001', 'amount' => '100', 'received_on' => $today])->assertSessionHasErrors('reference');
        $invoice = ContractorInvoice::query()->firstOrFail();

        $this->actingAs($owner)->post(route('invoices.move', $invoice), ['status' => 'paid'])->assertSessionHas('error');
        $this->actingAs($owner)->post(route('invoices.move', $invoice), ['status' => 'disputed'])->assertSessionHasErrors('note');
        $this->actingAs($owner)->post(route('invoices.move', $invoice), ['status' => 'approved'])->assertSessionHas('status');
        $this->actingAs($owner)->post(route('invoices.move', $invoice), ['status' => 'paid'])->assertSessionHas('status');
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->actingAs($owner)->post(route('invoices.move', $invoice), ['status' => 'disputed', 'note' => 'late'])->assertSessionHas('error');
    }

    public function test_renewal_ending_reminders_and_classification_signals(): void
    {
        Carbon::setTestNow('2026-11-02 08:00:00');
        $owner = $this->owner();
        $this->actingAs($owner)->post(route('contractors.store'), $this->engagementPayload(['first_name' => 'Naledi', 'last_name' => 'Khumalo', 'ends_on' => '2026-11-20']));
        $engagement = Engagement::query()->firstOrFail();

        $this->artisan('hr:engagement-reminders')->expectsOutput('Reminded about 1 engagement(s).');
        $this->artisan('hr:engagement-reminders')->expectsOutput('Reminded about 0 engagement(s).');
        $this->assertTrue($owner->notifications()->exists());

        $this->actingAs($owner)->post(route('contractors.renew', $engagement), ['ends_on' => '2026-11-10'])->assertSessionHas('error');
        $this->actingAs($owner)->post(route('contractors.renew', $engagement), ['ends_on' => '2027-02-28'])->assertSessionHas('status');
        $this->actingAs($owner)->post(route('contractors.renew', $engagement), ['ends_on' => '2027-12-31'])->assertSessionHas('status');
        $engagement->refresh();
        $this->assertSame(2, $engagement->renewals);
        $this->assertNull($engagement->reminded_at);

        // Two renewals, more than a year, and on the staff roster: three prompts to take advice.
        $this->actingAs($owner)->post(route('schedule.store'), ['date' => '2026-11-03', 'start' => '07:00', 'end' => '16:00', 'break_minutes' => 60, 'employee_ids' => [$engagement->employee_id]]);
        $this->assertCount(1, Shift::query()->whereHas('employees')->get());
        $this->assertCount(3, $engagement->signals());

        $this->actingAs($owner)->post(route('contractors.end', $engagement), ['end_reason' => 'not_renewed', 'ended_on' => '2026-12-31'])->assertSessionHas('status');
        $this->assertSame('ended', $engagement->fresh()->status);
        $this->actingAs($owner)->post(route('contractors.milestones.store', $engagement), ['title' => 'More work'])->assertStatus(422);

        $actions = HrAuditLog::withoutTeamScope(fn () => HrAuditLog::query()->pluck('action')->all());
        foreach (['engagement.created', 'engagement.renewed', 'engagement.ended'] as $action) {
            $this->assertContains($action, $actions);
        }
    }

    public function test_a_written_review_runs_reflection_feedback_acknowledgement_and_stays_private(): void
    {
        $owner = $this->owner();
        $reviewer = $this->memberOf($owner, 'editor');
        $staff = $this->memberOf($owner, 'viewer');
        $colleague = $this->memberOf($owner, 'editor');
        $person = $this->employeeIn($owner->currentTeam, $staff, ['first_name' => 'Lerato']);
        $this->employeeIn($owner->currentTeam, $colleague);

        $this->actingAs($owner)->post(route('reviews.cycles.store'), ['name' => 'Second half 2026'])->assertRedirect();
        $cycle = ReviewCycle::query()->firstOrFail();
        $this->actingAs($owner)->post(route('reviews.cycle.people', $cycle), ['employee_ids' => [$person->id], 'reviewer_id' => $reviewer->id])->assertSessionHas('status');
        $review = Review::query()->firstOrFail();
        $this->assertSame('self', $review->status);

        // Nobody else in the business can open it — not even another manager.
        $this->actingAs($colleague)->get(route('reviews.show', $review))->assertForbidden();
        $this->actingAs($reviewer)->post(route('reviews.share', $review), ['feedback' => str_repeat('Feedback before reflection. ', 3)])->assertSessionHas('error');

        $reflection = 'I ran the receiving bay alone for three weeks and learned the stock system properly.';
        $this->actingAs($staff)->post(route('reviews.reflect', $review), ['self_assessment' => $reflection])->assertSessionHas('status');
        $this->actingAs($staff)->post(route('reviews.share', $review), ['feedback' => str_repeat('Not mine to write. ', 3)])->assertForbidden();

        $feedback = 'You kept receiving accurate under pressure. Next: teach the count routine to the new starters.';
        $this->actingAs($reviewer)->post(route('reviews.share', $review), ['feedback' => $feedback])->assertSessionHas('status');
        $this->actingAs($staff)->get(route('reviews.show', $review))->assertOk()->assertSee('teach the count routine');
        $this->actingAs($staff)->post(route('reviews.acknowledge', $review), ['response' => 'Agreed — I would like the trainer course.'])->assertSessionHas('status');
        $this->assertSame('acknowledged', $review->fresh()->status);

        // Encrypted where it is stored; the audit log holds field names and statuses only.
        $raw = DB::table('reviews')->where('id', $review->id)->first();
        $this->assertStringNotContainsString('receiving bay', (string) $raw->self_assessment);
        $this->assertStringNotContainsString('count routine', (string) $raw->feedback);
        $log = HrAuditLog::withoutTeamScope(fn () => HrAuditLog::query()->get());
        $this->assertStringNotContainsString('receiving bay', $log->toJson());
        $this->assertContains('review.shared', $log->pluck('action')->all());

        // An HR administrator reading someone else's review leaves a trace.
        $this->actingAs($owner)->get(route('reviews.show', $review))->assertOk();
        $this->assertContains('review.viewed', HrAuditLog::withoutTeamScope(fn () => HrAuditLog::query()->pluck('action')->all()));

        $stranger = $this->owner();
        $this->actingAs($stranger)->get(route('reviews.show', $review))->assertNotFound();
    }

    public function test_reviews_refuse_self_review_and_skip_reflection_without_an_account(): void
    {
        $owner = $this->owner();
        $self = $this->employeeIn($owner->currentTeam, $owner);
        $noAccount = $this->employeeIn($owner->currentTeam, null, ['first_name' => 'Sipho']);
        $this->actingAs($owner)->post(route('reviews.cycles.store'), ['name' => 'Probation reviews']);
        $cycle = ReviewCycle::query()->firstOrFail();

        $this->actingAs($owner)->post(route('reviews.cycle.people', $cycle), ['employee_ids' => [$self->id, $noAccount->id], 'reviewer_id' => $owner->id])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'nobody reviews themselves'));
        $this->assertSame(1, Review::query()->count());
        $this->assertSame('reviewer', Review::query()->firstOrFail()->status);
    }

    public function test_goals_belong_to_the_person_their_reviewer_and_hr(): void
    {
        $owner = $this->owner();
        $staff = $this->memberOf($owner, 'viewer');
        $colleague = $this->memberOf($owner, 'editor');
        $person = $this->employeeIn($owner->currentTeam, $staff);

        $this->actingAs($staff)->post(route('my-work.goals.store'), ['title' => 'Lead a stocktake on my own', 'kind' => 'development'])->assertSessionHas('status');
        $goal = Goal::query()->firstOrFail();
        $this->assertSame($person->id, $goal->employee_id);

        $this->actingAs($colleague)->put(route('goals.update', $goal), ['status' => 'done'])->assertForbidden();
        $this->actingAs($colleague)->post(route('goals.store', $person), ['title' => 'Not my call', 'kind' => 'performance'])->assertForbidden();
        $this->actingAs($staff)->put(route('goals.update', $goal), ['status' => 'on_track'])->assertSessionHas('status');
        $this->actingAs($owner)->put(route('goals.update', $goal), ['status' => 'done'])->assertSessionHas('status');
        $this->assertSame('done', $goal->fresh()->status);
    }

    public function test_training_is_requested_decided_by_someone_else_and_completion_closes_a_readiness_gap(): void
    {
        $owner = $this->owner();
        $supervisor = $this->memberOf($owner, 'editor');
        $this->grant($owner, $supervisor, HrGrant::SUPERVISOR);
        $staff = $this->memberOf($owner, 'viewer');
        $position = new Position;
        $position->forceFill(['team_id' => $owner->currentTeam->id, 'title' => 'Warehouse associate'])->save();
        $person = $this->employeeIn($owner->currentTeam, $staff, ['position_id' => $position->id]);
        $supervisorRecord = $this->employeeIn($owner->currentTeam, $supervisor);
        $this->actingAs($owner)->post(route('requirements.store', $position), ['name' => 'First aid level 1']);
        $this->assertCount(1, app(Readiness::class)->gaps($owner->currentTeam->id));

        $this->actingAs($staff)->get(route('training.index'))->assertForbidden();
        $this->actingAs($staff)->post(route('my-work.training.store'), ['title' => 'First aid level 1', 'kind' => 'external', 'reason' => 'I am on the floor alone on Saturdays'])->assertSessionHas('status');
        $request = TrainingRecord::query()->firstOrFail();
        $this->assertSame('requested', $request->status);

        // The supervisor's own request goes to someone else.
        $this->actingAs($supervisor)->post(route('my-work.training.store'), ['title' => 'Supervisor course', 'kind' => 'external']);
        $own = TrainingRecord::query()->where('employee_id', $supervisorRecord->id)->firstOrFail();
        $this->actingAs($supervisor)->post(route('training.decide', [$own, 'approve']))->assertSessionHas('error');

        $this->actingAs($supervisor)->post(route('training.decide', [$request, 'approve']), ['starts_on' => now()->toDateString()])->assertSessionHas('status');
        $this->assertSame('planned', $request->fresh()->status);

        $this->actingAs($supervisor)->post(route('training.complete', $request), [
            'completed_on' => now()->toDateString(), 'hours' => 8, 'certification_name' => 'first aid level 1',
            'certification_expires_on' => now()->addYears(3)->toDateString(),
        ])->assertSessionHas('status');

        $this->assertSame('completed', $request->fresh()->status);
        $this->assertTrue(EmployeeCertification::query()->where('employee_id', $person->id)->exists());
        $this->actingAs($owner);
        $this->assertCount(0, app(Readiness::class)->gaps($owner->currentTeam->id));
    }
}
