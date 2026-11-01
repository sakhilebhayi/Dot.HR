<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\HiringStep;
use App\Models\HrGrant;
use App\Models\ProfessionalProfile;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * Each business's own hiring steps inside the fixed stages (never shown to candidates), and
 * hiring costs with cost per hire that says what it does not count.
 */
class HiringStepsAndCostsTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    private function role(User $owner): Vacancy
    {
        $this->actingAs($owner)->post(route('hiring.store'), [
            'title' => 'Bookkeeper', 'description' => 'Keep the books of a growing wholesaler.', 'skills' => 'Pastel Partner',
            'work_type' => 'full_time', 'arrangement' => 'on_site', 'location' => 'Mahikeng', 'pay_basis' => 'salary',
            'pay_min' => '18000', 'pay_max' => '22000', 'currency' => 'ZAR', 'openings' => 1, 'closes_on' => now()->addWeeks(2)->toDateString(),
        ])->assertRedirect();
        $vacancy = Vacancy::withoutTeamScope(fn () => Vacancy::query()->latest('id')->firstOrFail());
        $this->actingAs($owner)->post(route('hiring.transition', [$vacancy, 'publish']))->assertRedirect();

        return $vacancy;
    }

    private function applicant(Vacancy $vacancy, string $name): Application
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => $name]);
        ProfessionalProfile::forUser($user);
        $this->actingAs($user)->post(route('jobs.apply', $vacancy->id), ['share' => '1'])->assertRedirect();

        return Application::withoutTeamScope(fn () => Application::query()->where('user_id', $user->id)->sole());
    }

    private function step(User $owner, string $stage, string $name): HiringStep
    {
        $this->actingAs($owner)->post(route('hiring.steps.store'), ['stage' => $stage, 'name' => $name])->assertRedirect()->assertSessionHasNoErrors();

        return HiringStep::withoutTeamScope(fn () => HiringStep::query()->latest('id')->firstOrFail());
    }

    public function test_a_business_adds_its_own_steps_and_candidates_never_see_them(): void
    {
        $owner = $this->owner();
        $manager = $this->memberOf($owner, 'viewer');
        HrGrant::create(['team_id' => $owner->currentTeam->id, 'user_id' => $manager->id, 'capability' => HrGrant::HIRING_MANAGER, 'granted_by' => $owner->id]);
        $this->actingAs($manager)->get(route('hiring.steps'))->assertForbidden();

        $phone = $this->step($owner, 'review', 'Phone screen');
        $test = $this->step($owner, 'review', 'Skills test');
        $refs = $this->step($owner, 'offer', 'Reference check');
        $this->actingAs($owner)->post(route('hiring.steps.move', [$test, 'up']))->assertRedirect();
        $this->assertSame(['Skills test', 'Phone screen'], HiringStep::withoutTeamScope(fn () => HiringStep::query()->live()->where('stage', 'review')->pluck('name')->all()));

        $vacancy = $this->role($owner);
        $application = $this->applicant($vacancy, 'Ayanda Khumalo');
        $this->actingAs($owner)->post(route('hiring.applications.move', $application), ['stage' => 'review'])->assertRedirect();

        // Only a step in the application's own stage.
        $this->actingAs($owner)->post(route('hiring.applications.step', $application), ['hiring_step_id' => $refs->id])->assertStatus(422);
        $this->actingAs($manager)->post(route('hiring.applications.step', $application), ['hiring_step_id' => $phone->id])->assertForbidden();
        $this->actingAs($owner)->post(route('hiring.applications.step', $application), ['hiring_step_id' => $phone->id])->assertRedirect();
        $this->assertSame($phone->id, $application->fresh()->hiring_step_id);
        $this->actingAs($owner)->get(route('hiring.applications.show', $application))->assertSee('In review: Phone screen')->assertSee('Step: Phone screen');
        $this->actingAs($owner)->get(route('hiring.show', $vacancy))->assertSee('Phone screen');

        // The candidate sees the plain stage, never the business's step.
        $this->actingAs($application->user)->get(route('me.applications.show', $application->id))->assertOk()->assertDontSee('Phone screen');

        // Moving on leaves the step behind; retiring a step clears it from applications.
        $this->actingAs($owner)->post(route('hiring.applications.move', $application), ['stage' => 'shortlisted'])->assertRedirect();
        $this->assertNull($application->fresh()->hiring_step_id);
        $this->actingAs($owner)->post(route('hiring.applications.move', $application), ['stage' => 'review'])->assertRedirect();
        $this->actingAs($owner)->post(route('hiring.applications.step', $application), ['hiring_step_id' => $test->id])->assertRedirect();
        $this->actingAs($owner)->post(route('hiring.steps.retire', $test))->assertRedirect();
        $this->assertNull($application->fresh()->hiring_step_id);
        $this->assertSame('review', $application->fresh()->stage);
    }

    public function test_hiring_costs_give_cost_per_hire_and_say_what_they_leave_out(): void
    {
        $owner = $this->owner();
        $manager = $this->memberOf($owner, 'viewer');
        HrGrant::create(['team_id' => $owner->currentTeam->id, 'user_id' => $manager->id, 'capability' => HrGrant::HIRING_MANAGER, 'granted_by' => $owner->id]);
        $vacancy = $this->role($owner);
        $hired = $this->applicant($vacancy, 'Ayanda Khumalo');
        $this->applicant($vacancy, 'Bongani Ndlovu');

        $this->actingAs($manager)->post(route('hiring.costs.store', $vacancy), ['kind' => 'advertising', 'amount' => '1500', 'currency' => 'ZAR', 'incurred_on' => now()->toDateString()])->assertForbidden();
        $this->actingAs($owner)->post(route('hiring.costs.store', $vacancy), ['kind' => 'advertising', 'description' => 'Job board, 30 days', 'amount' => '1500', 'currency' => 'zar', 'incurred_on' => now()->toDateString()])->assertRedirect();
        $this->actingAs($owner)->post(route('hiring.costs.store', $vacancy), ['kind' => 'assessments', 'amount' => '900', 'currency' => 'ZAR', 'incurred_on' => now()->toDateString()])->assertRedirect();

        $this->actingAs($owner)->get(route('hiring.show', $vacancy))->assertSee('Total ZAR 2 400.00')->assertSee('no hires yet');
        $hired->forceFill(['stage' => 'hired', 'stage_changed_at' => now()])->save();
        $this->actingAs($owner)->get(route('hiring.show', $vacancy))->assertSee('ZAR 2 400.00 per hire (1 hire)')->assertSee('staff time is not counted');
        $this->actingAs($manager)->get(route('hiring.show', $vacancy))->assertOk()->assertDontSee('What this role cost to fill');

        $this->actingAs($owner)->get(route('insights.index'))->assertSee('What hiring cost, last 12 months')->assertSee('ZAR 2 400.00 per hire across 1 hire');
        $csv = $this->actingAs($owner)->get(route('insights.export', ['section' => 'hiring']))->streamedContent();
        $this->assertStringContainsString('"Cost per hire (ZAR)",2400', $csv);
    }
}
