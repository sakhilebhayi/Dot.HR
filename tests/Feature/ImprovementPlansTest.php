<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ImprovementPlan;
use App\Models\ImprovementPlanCheckIn;
use App\Models\TrainingRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * Performance improvement plans (brief §10 and §15; LRA Schedule 8, items 8 and 9): clear
 * standard, support, a reasonable time, check-ins the person can answer, an outcome that is
 * not itself a sanction. Seen by HR, the named supporting manager and the person only.
 */
class ImprovementPlansTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    /** @return object{owner: User, manager: User, otherManager: User, staff: User, other: User, employee: Employee} */
    private function team(): object
    {
        $owner = $this->owner();
        $manager = $this->memberOf($owner, 'editor');
        $otherManager = $this->memberOf($owner, 'editor');
        $staff = $this->memberOf($owner, 'viewer');
        $other = $this->memberOf($owner, 'viewer');
        $employee = $this->employeeIn($owner->currentTeam, $staff, ['first_name' => 'Thandi', 'last_name' => 'Mokoena']);
        $this->employeeIn($owner->currentTeam, $other);

        return (object) compact('owner', 'manager', 'otherManager', 'staff', 'other', 'employee');
    }

    private function draft(object $t, array $extra = []): ImprovementPlan
    {
        $this->actingAs($t->owner)->post(route('plans.store'), $extra + [
            'employee_id' => $t->employee->id, 'title' => 'Accuracy of stock counts',
            'concern' => 'Three of the last five month-end counts were out by more than 2%.',
            'standard' => 'Counts within 0.5% of the system, signed off on the day.',
            'support' => 'Two counts done together and a scanner refresher.',
            'starts_on' => now()->toDateString(), 'ends_on' => now()->addWeeks(8)->toDateString(), 'check_in_days' => 14,
            'manager_user_id' => $t->manager->id, 'training' => 'Scanner refresher',
            'objectives' => [
                ['title' => 'Accurate month-end count', 'measure' => 'Within 0.5% of the system', 'due_on' => now()->addWeeks(4)->toDateString()],
                ['title' => 'Same-day sign-off', 'measure' => 'Signed sheet filed the same day'],
                ['title' => '', 'measure' => ''],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        return ImprovementPlan::withoutTeamScope(fn () => ImprovementPlan::query()->latest('id')->firstOrFail());
    }

    public function test_a_draft_stays_with_hr_and_the_named_manager(): void
    {
        $t = $this->team();
        $plan = $this->draft($t);

        $this->assertSame('draft', $plan->status);
        $this->assertCount(2, $plan->objectives);
        $this->assertStringNotContainsString('month-end', (string) DB::table('improvement_plans')->where('id', $plan->id)->value('concern'));
        $this->assertTrue(TrainingRecord::withoutTeamScope(fn () => TrainingRecord::query()->where('employee_id', $t->employee->id)->where('status', 'planned')->exists()));
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $t->manager->id]);

        $this->actingAs($t->manager)->get(route('plans.show', $plan))->assertOk()->assertSee('month-end');
        $this->actingAs($t->manager)->get(route('plans.index'))->assertOk()->assertSee('Accuracy of stock counts');
        $this->actingAs($t->otherManager)->get(route('plans.show', $plan))->assertNotFound();
        $this->actingAs($t->otherManager)->get(route('plans.index'))->assertForbidden();
        $this->actingAs($t->other)->get(route('plans.show', $plan))->assertNotFound();

        // The person sees nothing of a draft, and never the HR side of their own plan.
        $this->actingAs($t->staff)->get(route('my-work.plans.show', $plan))->assertNotFound();
        $this->actingAs($t->staff)->get(route('my-work.index'))->assertDontSee('Accuracy of stock counts');
        $this->actingAs($t->staff)->get(route('plans.show', $plan))->assertNotFound();
    }

    public function test_the_full_plan_runs_with_the_person_able_to_answer_at_every_step(): void
    {
        $t = $this->team();
        $plan = $this->draft($t);

        $this->actingAs($t->owner)->post(route('plans.share', $plan))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $t->staff->id]);
        $this->actingAs($t->staff)->get(route('my-work.index'))->assertSee('Please read');
        $this->actingAs($t->staff)->get(route('my-work.plans.show', $plan))->assertOk()->assertSee('scanner refresher')->assertSee('does not mean I agree');

        $this->actingAs($t->staff)->post(route('my-work.plans.acknowledge', $plan), ['confirm' => 1, 'response' => 'The scanner keeps dropping the connection.'])->assertRedirect();
        $this->assertNotNull($plan->fresh()->acknowledged_at);
        $this->actingAs($t->manager)->get(route('plans.show', $plan))->assertSee('The scanner keeps dropping');

        $first = $plan->objectives->first();
        $this->actingAs($t->manager)->post(route('plans.check-ins.store', $plan), [
            'held_on' => now()->toDateString(), 'notes' => 'Counted together on Friday; within 0.4%.', 'objective' => [$first->id => 'met'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('met', $first->fresh()->status);
        $this->actingAs($t->otherManager)->post(route('plans.check-ins.store', $plan), ['held_on' => now()->toDateString(), 'notes' => 'Not my plan at all.'])->assertNotFound();

        $checkIn = ImprovementPlanCheckIn::withoutTeamScope(fn () => ImprovementPlanCheckIn::query()->sole());
        $this->actingAs($t->staff)->post(route('my-work.plans.comment', $checkIn), ['comment' => 'Thank you, the second count helped.'])->assertRedirect();
        $this->actingAs($t->manager)->get(route('plans.show', $plan))->assertSee('the second count helped');

        $this->actingAs($t->manager)->post(route('plans.extend', $plan), ['ends_on' => now()->addWeeks(4)->toDateString(), 'reason' => 'Too early'])->assertSessionHasErrors('ends_on');
        $this->actingAs($t->manager)->post(route('plans.extend', $plan), ['ends_on' => now()->addWeeks(10)->toDateString(), 'reason' => 'Real progress; training moved.'])->assertRedirect();
        $this->assertSame(1, $plan->fresh()->extensions);
        $this->actingAs($t->staff)->get(route('my-work.plans.show', $plan))->assertSee('extended to');

        $this->actingAs($t->owner)->post(route('plans.complete', $plan), ['outcome' => 'not_met', 'notes' => 'Two later counts were out by 3%.'])->assertRedirect();
        $this->actingAs($t->owner)->get(route('plans.show', $plan))->assertSee('incapacity enquiry')->assertSee('employee='.$t->employee->id, false);
        $this->actingAs($t->staff)->get(route('my-work.plans.show', $plan))->assertSee('The standard is still not met');
    }

    public function test_a_short_plan_is_questioned_and_a_plan_needs_measurable_objectives(): void
    {
        $t = $this->team();
        $short = $this->draft($t, ['ends_on' => now()->addDays(10)->toDateString()]);
        $this->actingAs($t->owner)->post(route('plans.share', $short))->assertSessionHas('status', fn ($s) => str_contains($s, 'reasonable time to improve'));

        $base = [
            'employee_id' => $t->employee->id, 'title' => 'Timekeeping', 'concern' => 'Late four times this month.', 'standard' => 'On time every shift.',
            'support' => 'A later shift pattern for a month.', 'starts_on' => now()->toDateString(), 'ends_on' => now()->addWeeks(6)->toDateString(), 'check_in_days' => 14,
        ];
        $this->actingAs($t->owner)->post(route('plans.store'), $base + ['objectives' => [['title' => 'Arrive on time', 'measure' => '']]])->assertSessionHasErrors('objectives.0.measure');
        $this->actingAs($t->owner)->post(route('plans.store'), ['ends_on' => now()->subDay()->toDateString()] + $base + ['objectives' => [['title' => 'On time', 'measure' => 'Clock-in record']]])->assertSessionHasErrors('ends_on');
        $this->actingAs($t->owner)->post(route('plans.store'), $base + ['manager_user_id' => $t->staff->id, 'objectives' => [['title' => 'On time', 'measure' => 'Clock-in record']]])->assertSessionHasErrors('manager_user_id');
        $this->actingAs($t->manager)->post(route('plans.store'), $base + ['objectives' => [['title' => 'On time', 'measure' => 'Clock-in record']]])->assertForbidden();
    }

    public function test_exports_and_retention(): void
    {
        $t = $this->team();
        $draft = $this->draft($t);
        $shared = $this->draft($t, ['title' => 'Customer follow-ups']);
        $this->actingAs($t->owner)->post(route('plans.share', $shared))->assertRedirect();

        $mine = $this->actingAs($t->staff)->get(route('my-work.data'))->assertOk()->json();
        $this->assertSame(['Customer follow-ups'], array_column($mine['improvement_plans'], 'title'));
        $hr = $this->actingAs($t->owner)->get(route('employees.personal-data', $t->employee))->assertOk()->json();
        $this->assertCount(2, $hr['improvement_plans']);

        $this->actingAs($t->owner)->post(route('plans.cancel', $draft), ['reason' => 'Drafted in error.'])->assertRedirect();
        $draft->refresh()->forceFill(['decided_at' => now()->subDays(365 * 5 + 1)])->save();
        $this->artisan('hr:retention')->assertSuccessful();
        $this->assertDatabaseMissing('improvement_plans', ['id' => $draft->id]);
        $this->assertDatabaseHas('improvement_plans', ['id' => $shared->id]);
    }
}
