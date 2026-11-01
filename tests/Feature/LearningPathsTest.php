<?php

namespace Tests\Feature;

use App\Models\Competency;
use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Models\HrGrant;
use App\Models\LearningPath;
use App\Models\LearningPathEnrolment;
use App\Models\LearningPathStep;
use App\Models\Position;
use App\Models\PositionCompetency;
use App\Models\TrainingRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * Learning paths (brief §10): ordered steps, courses done through their training record, other
 * steps marked done by the person or HR, progress as steps done and never a score.
 */
class LearningPathsTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    /** @return object{owner: User, supervisor: User, staff: User, other: User, employee: Employee, colleague: Employee, position: Position} */
    private function team(): object
    {
        $owner = $this->owner();
        $supervisor = $this->memberOf($owner, 'viewer');
        HrGrant::create(['team_id' => $owner->currentTeam->id, 'user_id' => $supervisor->id, 'capability' => HrGrant::SUPERVISOR, 'granted_by' => $owner->id]);
        $staff = $this->memberOf($owner, 'viewer');
        $other = $this->memberOf($owner, 'viewer');
        $position = Position::factory()->create(['team_id' => $owner->currentTeam->id, 'title' => 'Cashier']);
        $employee = $this->employeeIn($owner->currentTeam, $staff, ['first_name' => 'Thandi', 'position_id' => $position->id]);
        $colleague = $this->employeeIn($owner->currentTeam, $other, ['first_name' => 'Sipho', 'position_id' => $position->id]);

        return (object) compact('owner', 'supervisor', 'staff', 'other', 'employee', 'colleague', 'position');
    }

    /** A cashier path: a course that gives a certificate, then a practice step. */
    private function path(object $t, array $extra = []): LearningPath
    {
        $this->actingAs($t->owner)->post(route('learning.paths.store'), $extra + ['name' => 'A new cashier’s first month', 'position_id' => $t->position->id])->assertRedirect()->assertSessionHasNoErrors();
        $path = LearningPath::withoutTeamScope(fn () => LearningPath::query()->latest('id')->firstOrFail());
        $this->actingAs($t->owner)->post(route('learning.steps.store', $path), ['title' => 'Cash handling course', 'kind' => 'course', 'provider' => 'Retail Skills', 'certification_name' => 'Cash handling'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($t->owner)->post(route('learning.steps.store', $path), ['title' => 'Two cash-ups with a supervisor', 'kind' => 'practice', 'within_days' => '14'])->assertRedirect()->assertSessionHasNoErrors();

        return $path;
    }

    private function step(LearningPath $path, string $kind): LearningPathStep
    {
        return LearningPathStep::withoutTeamScope(fn () => LearningPathStep::query()->where('learning_path_id', $path->id)->where('kind', $kind)->sole());
    }

    private function enrolmentOf(Employee $e): LearningPathEnrolment
    {
        return LearningPathEnrolment::withoutTeamScope(fn () => LearningPathEnrolment::query()->where('employee_id', $e->id)->sole());
    }

    public function test_a_path_runs_from_enrolment_through_its_course_to_completion(): void
    {
        $t = $this->team();
        $this->actingAs($t->supervisor)->post(route('learning.paths.store'), ['name' => 'X'])->assertForbidden();
        $this->actingAs($t->staff)->get(route('learning.index'))->assertForbidden();
        $path = $this->path($t);
        [$course, $practice] = [$this->step($path, 'course'), $this->step($path, 'practice')];

        // A supervisor puts everyone in the position on it; both are told.
        $this->actingAs($t->supervisor)->get(route('learning.paths.show', $path))->assertOk()->assertSee('Put everyone in Cashier on it')->assertDontSee('Add a step');
        $this->actingAs($t->supervisor)->post(route('learning.enrol-position', $path))->assertRedirect();
        $this->assertSame(2, LearningPathEnrolment::withoutTeamScope(fn () => LearningPathEnrolment::query()->count()));
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $t->staff->id]);
        $mine = $this->enrolmentOf($t->employee);

        // The person cannot tick a course; they ask for it, and it becomes an ordinary training request.
        $this->actingAs($t->staff)->post(route('my-work.learning.done', [$mine, $course]))->assertSessionHasErrors('step');
        $this->actingAs($t->staff)->post(route('my-work.learning.ask', [$mine, $course]))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($t->staff)->post(route('my-work.learning.ask', [$mine, $course]))->assertSessionHasErrors('step');
        $record = TrainingRecord::withoutTeamScope(fn () => TrainingRecord::query()->sole());
        $this->assertSame(['requested', 'Cash handling course', 'Cash handling'], [$record->status, $record->title, $record->certification_name]);
        $this->actingAs($t->staff)->get(route('my-work.learning.show', $mine))->assertOk()->assertSee('Asked for')->assertSee('0 of 2');

        $this->actingAs($t->staff)->post(route('my-work.learning.done', [$mine, $practice]))->assertRedirect()->assertSessionHasNoErrors();

        // Training approves and completes it: the course step is done, the path is complete,
        // the certificate is on file, and whoever put Thandi on the path is told.
        $this->actingAs($t->owner)->post(route('training.decide', [$record, 'approve']))->assertRedirect();
        $this->actingAs($t->owner)->post(route('training.complete', $record), ['completed_on' => now()->toDateString()])->assertRedirect();
        $mine->refresh();
        $this->assertNotNull($mine->completed_at);
        $this->assertTrue(EmployeeCertification::withoutTeamScope(fn () => EmployeeCertification::query()->where('employee_id', $t->employee->id)->where('name', 'Cash handling')->exists()));
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $t->supervisor->id]);

        $this->flushSession();
        $this->actingAs($t->staff)->get(route('my-work.learning.index'))->assertOk()->assertSee('Completed');
        $this->actingAs($t->supervisor)->get(route('learning.paths.show', $path))->assertSee('Completed')->assertSee('0 of 2 steps done');
        $export = $this->actingAs($t->staff)->get(route('my-work.data'))->json();
        $this->assertSame('A new cashier’s first month', $export['learning_paths'][0]['path']);
        $this->assertCount(2, $export['learning_paths'][0]['steps_done']);
    }

    public function test_who_may_undo_a_step_and_a_declined_course_can_be_asked_for_again(): void
    {
        $t = $this->team();
        $path = $this->path($t);
        [$course, $practice] = [$this->step($path, 'course'), $this->step($path, 'practice')];
        $this->actingAs($t->owner)->post(route('learning.enrol', $path), ['employee_ids' => [$t->employee->id]])->assertRedirect();
        $mine = $this->enrolmentOf($t->employee);

        // Another person's path is not theirs to see or touch.
        $this->actingAs($t->other)->get(route('my-work.learning.show', $mine))->assertNotFound();
        $this->actingAs($t->other)->post(route('my-work.learning.done', [$mine, $practice]))->assertNotFound();

        // HR marked it, so only HR undoes it.
        $this->actingAs($t->owner)->post(route('learning.steps.done', [$mine, $practice]), ['done_on' => now()->toDateString()])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($t->staff)->post(route('my-work.learning.undo', [$mine, $practice]))->assertSessionHasErrors('step');
        $this->actingAs($t->owner)->post(route('learning.steps.undo', [$mine, $practice]))->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($t->staff)->post(route('my-work.learning.ask', [$mine, $course]))->assertRedirect();
        $record = TrainingRecord::withoutTeamScope(fn () => TrainingRecord::query()->sole());
        $this->actingAs($t->owner)->post(route('training.decide', [$record, 'decline']), ['note' => 'Next quarter'])->assertRedirect();
        $this->actingAs($t->staff)->post(route('my-work.learning.ask', [$mine, $course]))->assertRedirect()->assertSessionHasNoErrors();

        // HR books a course step directly too, and retiring the last open step finishes the path.
        $this->actingAs($t->owner)->post(route('learning.enrol', $path), ['employee_ids' => [$t->colleague->id]])->assertRedirect();
        $theirs = $this->enrolmentOf($t->colleague);
        $this->actingAs($t->owner)->post(route('learning.steps.book', [$theirs, $course]), ['starts_on' => now()->addWeek()->toDateString()])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($t->owner)->post(route('learning.steps.done', [$theirs, $practice]))->assertRedirect();
        $this->actingAs($t->owner)->post(route('learning.steps.retire', $course))->assertRedirect();
        $this->assertNotNull($theirs->fresh()->completed_at);
        $this->assertNull($mine->fresh()->completed_at, 'Thandi has not done the practice step');
    }

    public function test_open_paths_people_start_themselves_and_gaps_point_at_the_step_that_grows_them(): void
    {
        $t = $this->team();
        $closed = $this->path($t);
        $this->actingAs($t->staff)->post(route('my-work.learning.join', $closed))->assertNotFound();

        $this->actingAs($t->owner)->post(route('competencies.store'), ['name' => 'Handling cash', 'level_1' => 'a', 'level_2' => 'b', 'level_3' => 'c'])->assertRedirect();
        $cash = Competency::withoutTeamScope(fn () => Competency::query()->sole());
        PositionCompetency::query()->forceCreate(['team_id' => $t->position->team_id, 'position_id' => $t->position->id, 'competency_id' => $cash->id, 'expected_level' => 2]);

        $this->actingAs($t->owner)->post(route('learning.paths.store'), ['name' => 'Supervising a shift', 'self_enrol' => '1'])->assertRedirect();
        $open = LearningPath::withoutTeamScope(fn () => LearningPath::query()->where('name', 'Supervising a shift')->sole());
        $this->actingAs($t->owner)->post(route('learning.steps.store', $open), ['title' => 'Run the Friday cash-up', 'kind' => 'practice', 'competency_id' => $cash->id])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($t->staff)->get(route('my-work.learning.index'))->assertOk()->assertSee('Paths you can start')->assertSee('Supervising a shift')->assertDontSee('first month');
        $this->actingAs($t->staff)->get(route('my-work.competencies'))->assertSee('“Run the Friday cash-up” on Supervising a shift', false);
        $this->actingAs($t->staff)->post(route('my-work.learning.join', $open))->assertRedirect();
        $mine = $this->enrolmentOf($t->employee);
        $this->assertSame($t->staff->id, $mine->assigned_by);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $t->staff->id]);

        $this->actingAs($t->staff)->post(route('my-work.learning.done', [$mine, $this->step($open, 'practice')]))->assertRedirect();
        $this->assertNotNull($mine->fresh()->completed_at);

        // A retired path leaves the list; the record stays.
        $this->actingAs($t->owner)->post(route('learning.paths.retire', $open))->assertRedirect();
        $this->actingAs($t->owner)->get(route('learning.paths.show', $open))->assertNotFound();
        $this->assertNotNull($mine->fresh()->completed_at);
    }
}
