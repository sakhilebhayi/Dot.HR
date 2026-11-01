<?php

namespace Tests\Feature;

use App\Models\Competency;
use App\Models\Employee;
use App\Models\FeedbackResponse;
use App\Models\FeedbackRound;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * Competency frameworks and 360 feedback (brief §10), without scores: levels are written
 * descriptions agreed with the person; colleagues' words reach the person without names and
 * only once enough people answered.
 */
class CompetenciesAndFeedbackTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    /** @return object{owner: User, manager: User, staff: User, others: list<User>, employee: Employee, position: Position} */
    private function team(): object
    {
        $owner = $this->owner();
        $manager = $this->memberOf($owner, 'editor');
        $staff = $this->memberOf($owner, 'viewer');
        $others = [$this->memberOf($owner, 'viewer'), $this->memberOf($owner, 'viewer'), $this->memberOf($owner, 'viewer')];
        $position = Position::factory()->create(['team_id' => $owner->currentTeam->id, 'title' => 'Cashier']);
        $employee = $this->employeeIn($owner->currentTeam, $staff, ['first_name' => 'Thandi', 'last_name' => 'Mokoena', 'position_id' => $position->id]);

        return (object) compact('owner', 'manager', 'staff', 'others', 'employee', 'position');
    }

    private function competency(User $owner): Competency
    {
        $this->actingAs($owner)->post(route('competencies.store'), [
            'area' => 'Shop floor', 'name' => 'Handling cash', 'level_1' => 'Counts a till with a supervisor checking.',
            'level_2' => 'Cashes up alone and balances to the cent.', 'level_3' => 'Trains others and sorts out discrepancies.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return Competency::withoutTeamScope(fn () => Competency::query()->latest('id')->firstOrFail());
    }

    public function test_the_framework_matrix_and_a_persons_place_in_it(): void
    {
        $t = $this->team();
        $this->actingAs($t->manager)->post(route('competencies.store'), ['name' => 'X', 'level_1' => 'a', 'level_2' => 'b', 'level_3' => 'c'])->assertForbidden();
        $cash = $this->competency($t->owner);
        $this->actingAs($t->owner)->put(route('competencies.matrix', $t->position), ['levels' => [$cash->id => '2']])->assertRedirect();

        // Expected Practising, nothing agreed yet: room to grow, with training one click away.
        $this->actingAs($t->staff)->get(route('my-work.competencies'))->assertOk()
            ->assertSee('Handling cash')->assertSee('Room to grow')->assertSee('Ask for training in Handling cash');

        $this->actingAs($t->staff)->post(route('my-work.competencies.assess', $cash), ['level' => '2', 'evidence' => 'Cashed up alone every Friday since March.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('Friday', (string) DB::table('competency_assessments')->value('self_evidence'));

        $this->actingAs($t->manager)->get(route('employees.competencies', $t->employee))->assertForbidden();
        $this->actingAs($t->owner)->get(route('employees.competencies', $t->employee))->assertOk()->assertSee('Cashed up alone every Friday');
        $this->actingAs($t->owner)->post(route('employees.competencies.agree', [$t->employee, $cash]), ['level' => '2', 'note' => 'Agreed: balanced every week.'])->assertRedirect();
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $t->staff->id]);

        $this->actingAs($t->staff)->get(route('my-work.competencies'))->assertSee('Meets what the position expects')->assertDontSee('Room to grow')->assertSee('Agreed: balanced every week.');

        $mine = $this->actingAs($t->staff)->get(route('my-work.data'))->json();
        $this->assertSame(['Handling cash', 'Practising', 'Practising'], [$mine['competencies'][0]['competency'], $mine['competencies'][0]['you_placed_yourself'], $mine['competencies'][0]['agreed']]);

        $this->actingAs($t->owner)->post(route('competencies.retire', $cash))->assertRedirect();
        $this->flushSession(); // the owner's "retired" message is not the staff page's content
        $this->actingAs($t->staff)->get(route('my-work.competencies'))->assertDontSee('Handling cash');
        $this->actingAs($t->staff)->post(route('my-work.competencies.assess', $cash), ['level' => '3'])->assertSessionHasErrors('level');
    }

    public function test_feedback_reaches_the_person_without_names_and_only_above_the_floor(): void
    {
        $t = $this->team();
        [$a, $b, $c] = $t->others;

        $this->actingAs($t->staff)->post(route('feedback.rounds.store'), ['givers' => [$a->id, $b->id], 'due_on' => now()->addWeek()->toDateString()])->assertSessionHasErrors('givers');
        $this->actingAs($t->staff)->post(route('feedback.rounds.store'), ['givers' => [$a->id, $b->id, $t->staff->id], 'due_on' => now()->addWeek()->toDateString()])->assertSessionHasErrors('givers');
        $this->actingAs($t->staff)->post(route('feedback.rounds.store'), ['givers' => [$a->id, $b->id, $c->id], 'focus' => 'How I run the stock meeting', 'due_on' => now()->addWeek()->toDateString()])->assertRedirect()->assertSessionHasNoErrors();

        $round = FeedbackRound::withoutTeamScope(fn () => FeedbackRound::query()->sole());
        $this->assertSame($t->employee->id, $round->employee_id);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $a->id]);
        $response = fn (User $u) => FeedbackResponse::withoutTeamScope(fn () => FeedbackResponse::query()->where('giver_user_id', $u->id)->sole());

        $this->actingAs($t->manager)->get(route('feedback.respond', $response($a)))->assertNotFound();
        $this->actingAs($a)->post(route('feedback.answer', $response($a)), ['keep_doing' => 'Starting the meeting on time.', 'do_differently' => 'Let the night shift speak first.'])->assertRedirect();
        $this->actingAs($b)->post(route('feedback.answer', $response($b)), ['keep_doing' => 'Writing the actions on the board.'])->assertRedirect();
        $this->assertStringNotContainsString('night shift', (string) DB::table('feedback_responses')->where('giver_user_id', $a->id)->value('do_differently'));

        // Two answers: nothing to read yet, and sharing is refused.
        $this->actingAs($t->staff)->get(route('feedback.rounds.show', $round))->assertOk()->assertDontSee('night shift');
        $this->actingAs($t->staff)->post(route('feedback.rounds.share', $round))->assertSessionHasErrors('round');

        $this->actingAs($c)->post(route('feedback.answer', $response($c)), ['anything_else' => 'Share the stock numbers before the meeting.'])->assertRedirect();
        $this->actingAs($t->staff)->post(route('feedback.rounds.share', $round))->assertRedirect()->assertSessionHasNoErrors();

        $page = $this->actingAs($t->staff)->get(route('feedback.rounds.show', $round))->assertOk();
        $page->assertSee('Let the night shift speak first.')->assertSee('Share the stock numbers');
        foreach ([$a, $b, $c] as $giver) {
            $page->assertDontSee($giver->name);
        }

        // HR sees names; a manager who is neither HR nor the person sees nothing.
        $this->actingAs($t->owner)->get(route('feedback.rounds.show', $round))->assertOk()->assertSee($a->name);
        $this->actingAs($t->manager)->get(route('feedback.rounds.show', $round))->assertNotFound();

        $mine = $this->actingAs($t->staff)->get(route('my-work.data'))->json();
        $this->assertCount(3, $mine['feedback_about_you'][0]['answers']);
        $this->assertStringNotContainsString($a->name, json_encode($mine['feedback_about_you']));
    }

    public function test_hr_can_run_a_round_about_anyone_and_close_it_unshared(): void
    {
        $t = $this->team();
        [$a, $b, $c] = $t->others;

        $this->actingAs($t->manager)->post(route('feedback.rounds.store'), ['employee_id' => $t->employee->id, 'givers' => [$a->id, $b->id, $c->id], 'due_on' => now()->addWeek()->toDateString()])->assertForbidden();
        $this->actingAs($t->owner)->post(route('feedback.rounds.store'), ['employee_id' => $t->employee->id, 'givers' => [$a->id, $b->id, $c->id], 'due_on' => now()->addWeek()->toDateString()])->assertRedirect()->assertSessionHasNoErrors();
        $round = FeedbackRound::withoutTeamScope(fn () => FeedbackRound::query()->sole());

        // The person did not ask for this round, so only HR shares it.
        $this->actingAs($t->staff)->get(route('feedback.rounds.show', $round))->assertOk()->assertDontSee('without names</button>', false);
        $this->actingAs($t->owner)->post(route('feedback.rounds.close', $round))->assertRedirect();
        $this->assertSame('closed', $round->fresh()->status);
    }
}
