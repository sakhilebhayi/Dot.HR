<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\HrGrant;
use App\Models\ProfessionalProfile;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * Candidate search across the business's own applicants, and moving several applications for a
 * role at once under the same rules as one at a time (R-32 brief §5).
 */
class HiringBulkAndSearchTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    private function role(User $owner, string $title): Vacancy
    {
        $this->actingAs($owner)->post(route('hiring.store'), [
            'title' => $title, 'description' => 'Keep the books of a growing wholesaler.', 'skills' => 'Pastel Partner',
            'work_type' => 'full_time', 'arrangement' => 'on_site', 'location' => 'Mahikeng', 'pay_basis' => 'salary',
            'pay_min' => '18000', 'pay_max' => '22000', 'currency' => 'ZAR', 'openings' => 1, 'closes_on' => now()->addWeeks(2)->toDateString(),
        ])->assertRedirect();
        $vacancy = Vacancy::withoutTeamScope(fn () => Vacancy::query()->latest('id')->firstOrFail());
        $this->actingAs($owner)->post(route('hiring.transition', [$vacancy, 'publish']))->assertRedirect();

        return $vacancy;
    }

    /** @param list<string> $skills */
    private function applicant(Vacancy $vacancy, string $name, array $skills = [], ?string $headline = null): Application
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@example.com']);
        $profile = ProfessionalProfile::forUser($user);
        $profile->forceFill(['headline' => $headline, 'location' => 'Mahikeng'])->save();
        foreach ($skills as $i => $skill) {
            $profile->skills()->create(['name' => $skill, 'normalized' => mb_strtolower($skill), 'position' => $i]);
        }
        $this->actingAs($user)->post(route('jobs.apply', $vacancy->id), ['share' => '1'])->assertRedirect()->assertSessionHasNoErrors();

        return Application::withoutTeamScope(fn () => Application::query()->where('user_id', $user->id)->where('vacancy_id', $vacancy->id)->sole());
    }

    public function test_several_candidates_move_at_once_under_the_usual_rules(): void
    {
        $owner = $this->owner();
        $role = $this->role($owner, 'Bookkeeper');
        $other = $this->role($owner, 'Driver');
        $a = $this->applicant($role, 'Ayanda Khumalo');
        $b = $this->applicant($role, 'Bongani Ndlovu');
        $c = $this->applicant($role, 'Carla Pieterse');
        $c->forceFill(['stage' => 'offer'])->save();
        $elsewhere = $this->applicant($other, 'Dineo Molefe');

        $this->actingAs($owner)->post(route('hiring.bulk', $role), [
            'application_ids' => [$a->id, $b->id, $c->id, $elsewhere->id], 'stage' => 'rejected',
            'message' => 'We chose someone with more Pastel experience. Thank you for applying.',
        ])->assertRedirect()->assertSessionHas('status', fn ($s) => str_contains($s, 'Moved 2') && str_contains($s, '2 could not move'));

        $this->assertSame(['rejected', 'rejected', 'offer', 'new'], [$a->fresh()->stage, $b->fresh()->stage, $c->fresh()->stage, $elsewhere->fresh()->stage]);
        $sent = DB::table('notifications')->where('notifiable_id', $a->user_id)->latest('created_at')->value('data');
        $this->assertStringContainsString('more Pastel experience', $sent);
        $this->assertSame(1, DB::table('application_events')->where('application_id', $a->id)->where('kind', 'stage')->count());

        $this->actingAs($owner)->post(route('hiring.bulk', $role), ['stage' => 'shortlisted'])->assertSessionHasErrors('application_ids');
        $this->actingAs($owner)->post(route('hiring.bulk', $role), ['application_ids' => [$a->id], 'stage' => 'interview'])->assertSessionHasErrors('stage');
    }

    public function test_only_recruiters_move_in_bulk_and_only_in_their_business(): void
    {
        $owner = $this->owner();
        $role = $this->role($owner, 'Bookkeeper');
        $a = $this->applicant($role, 'Ayanda Khumalo');
        $hiringManager = $this->memberOf($owner, 'viewer');
        HrGrant::create(['team_id' => $owner->currentTeam->id, 'user_id' => $hiringManager->id, 'capability' => HrGrant::HIRING_MANAGER, 'granted_by' => $owner->id]);

        $this->actingAs($hiringManager)->post(route('hiring.bulk', $role), ['application_ids' => [$a->id], 'stage' => 'shortlisted'])->assertForbidden();
        $this->actingAs($hiringManager)->get(route('hiring.show', $role))->assertOk()->assertDontSee('bulk-form', false);
        $this->actingAs($owner)->get(route('hiring.show', $role))->assertSee('bulk-form', false)->assertSee('data-check-all="new"', false);

        $stranger = $this->owner();
        $this->actingAs($stranger)->post(route('hiring.bulk', $role), ['application_ids' => [$a->id], 'stage' => 'shortlisted'])->assertNotFound();
        $this->assertSame('new', $a->fresh()->stage);
    }

    public function test_search_finds_applicants_by_skill_name_or_email_and_nobody_else(): void
    {
        $owner = $this->owner();
        $role = $this->role($owner, 'Bookkeeper');
        $driver = $this->role($owner, 'Driver');
        $this->applicant($role, 'Ayanda Khumalo', ['Pastel Partner', 'Payroll'], 'Bookkeeper for small firms');
        $b = $this->applicant($driver, 'Bongani Ndlovu', ['Code 10 licence']);
        $b->forceFill(['stage' => 'rejected'])->save();

        // Someone who applied to another business never appears here.
        $elsewhere = $this->owner();
        $this->applicant($this->role($elsewhere, 'Bookkeeper'), 'Zanele Outsider', ['Pastel Partner']);

        $this->actingAs($owner)->get(route('hiring.candidates', ['q' => 'pastel']))->assertOk()
            ->assertSee('Ayanda Khumalo')->assertDontSee('Bongani Ndlovu')->assertDontSee('Zanele Outsider');
        $this->actingAs($owner)->get(route('hiring.candidates', ['q' => 'bongani.ndlovu@example']))->assertSee('Bongani Ndlovu')->assertDontSee('Ayanda Khumalo');
        $this->actingAs($owner)->get(route('hiring.candidates', ['q' => 'small firms payroll']))->assertSee('Ayanda Khumalo');
        $this->actingAs($owner)->get(route('hiring.candidates', ['state' => 'decided']))->assertSee('Bongani Ndlovu')->assertDontSee('Ayanda Khumalo');
        $this->actingAs($owner)->get(route('hiring.candidates', ['role' => $driver->id]))->assertSee('Bongani Ndlovu')->assertDontSee('Ayanda Khumalo');
        $this->actingAs($owner)->get(route('hiring.candidates'))->assertSee('Ayanda Khumalo')->assertSee('Bongani Ndlovu')->assertDontSee('Zanele Outsider');

        $member = $this->memberOf($owner, 'viewer');
        $this->actingAs($member)->get(route('hiring.candidates'))->assertForbidden();
    }
}
