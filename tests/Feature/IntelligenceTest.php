<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Models\HrAuditLog;
use App\Models\HrGrant;
use App\Models\InternalInvitation;
use App\Models\Position;
use App\Models\ProfessionalProfile;
use App\Models\Team;
use App\Models\TrainingRecord;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\Intelligence\InternalTalent;
use App\Services\Recruitment\SkillMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * R-32 Phase 7, brief §10 and §13: explainable internal talent, skills coverage, training
 * suggestions and the business's own numbers — evidence from records only, never ranked,
 * never inferred, and profiles read only as their owners allow.
 */
class IntelligenceTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function role(User $recruiter, string $skills = 'Forklift licence, Stock control, Excel', bool $publish = true): Vacancy
    {
        $this->actingAs($recruiter)->post(route('hiring.store'), [
            'title' => 'Warehouse supervisor', 'description' => 'Run the receiving bay.', 'skills' => $skills,
            'work_type' => 'full_time', 'arrangement' => 'on_site', 'location' => 'Mahikeng', 'pay_basis' => 'undisclosed', 'currency' => 'ZAR', 'openings' => 1,
        ])->assertRedirect();
        $vacancy = Vacancy::query()->latest('id')->firstOrFail();
        if ($publish) {
            $this->actingAs($recruiter)->post(route('hiring.transition', [$vacancy, 'publish']));
        }

        return $vacancy->fresh();
    }

    private function profile(User $user, array $skills, string $visibility): void
    {
        $profile = ProfessionalProfile::forUser($user);
        $profile->forceFill(['visibility' => $visibility])->save();
        foreach ($skills as $i => $skill) {
            $profile->skills()->create(['name' => $skill, 'normalized' => SkillMatch::normalise($skill), 'position' => $i]);
        }
    }

    /** The factory picks a random employment type; these people are employees unless a test says otherwise. */
    private function person(Team $team, ?User $user = null, array $attributes = []): Employee
    {
        return $this->employeeIn($team, $user, array_merge(['employment_type' => 'full_time'], $attributes));
    }

    private function cert(int $teamId, int $employeeId, string $name, ?string $expires = null): void
    {
        $cert = new EmployeeCertification;
        $cert->forceFill(['team_id' => $teamId, 'employee_id' => $employeeId, 'name' => $name, 'expires_on' => $expires])->save();
    }

    public function test_internal_talent_shows_evidence_and_respects_profile_visibility(): void
    {
        $owner = $this->owner();
        $team = $owner->currentTeam;
        $vacancy = $this->role($owner);

        $amahle = $this->person($team, null, ['first_name' => 'Amahle', 'last_name' => 'Zulu']);
        $this->cert($team->id, $amahle->id, 'Forklift licence');
        $training = new TrainingRecord;
        $training->forceFill(['team_id' => $team->id, 'employee_id' => $amahle->id, 'title' => 'Stock control', 'kind' => 'internal', 'status' => 'completed', 'completed_on' => now()])->save();

        $openUser = $this->memberOf($owner, 'viewer');
        $this->person($team, $openUser, ['first_name' => 'Bongani', 'last_name' => 'Nkosi']);
        $this->profile($openUser, ['Excel'], 'employers');

        $privateUser = $this->memberOf($owner, 'viewer');
        $this->person($team, $privateUser, ['first_name' => 'Chantal', 'last_name' => 'Botha']);
        $this->profile($privateUser, ['Excel', 'Stock control'], 'private');

        $expired = $this->person($team, null, ['first_name' => 'Dineo']);
        $this->cert($team->id, $expired->id, 'Forklift licence', now()->subDay()->toDateString());
        $this->person($team, null, ['first_name' => 'Eddie', 'status' => 'terminated']);
        $contractor = $this->person($team, null, ['first_name' => 'Fikile', 'employment_type' => 'contractor']);
        $this->cert($team->id, $contractor->id, 'Forklift licence');

        $rows = app(InternalTalent::class)->forVacancy($vacancy, $owner);
        $this->assertSame(['Amahle', 'Bongani'], array_map(fn ($r) => $r['employee']->first_name, $rows));
        $this->assertSame([['name' => 'Forklift licence', 'source' => 'certificate'], ['name' => 'Stock control', 'source' => 'training']], $rows[0]['matched']);
        $this->assertSame(['Excel'], $rows[0]['missing']);
        $this->assertSame('profile', $rows[1]['matched'][0]['source']);

        $this->actingAs($owner)->get(route('hiring.show', $vacancy))->assertOk()
            ->assertSee('Already working here')->assertSee('certificate on file')->assertSee('training completed')
            ->assertDontSee('Chantal')->assertDontSee('Dineo')->assertDontSee('Fikile');
    }

    public function test_a_hiring_manager_sees_only_what_their_access_allows_and_applicants_are_not_repeated(): void
    {
        $owner = $this->owner();
        $team = $owner->currentTeam;
        $vacancy = $this->role($owner, 'Excel');
        $manager = $this->memberOf($owner, 'editor');
        HrGrant::query()->create(['team_id' => $team->id, 'user_id' => $manager->id, 'capability' => HrGrant::HIRING_MANAGER]);

        $openUser = $this->memberOf($owner, 'viewer');
        $this->person($team, $openUser, ['first_name' => 'Bongani']);
        $this->profile($openUser, ['Excel'], 'employers');

        // "Employers" visibility is for recruiters; a hiring manager is not one.
        $this->assertSame([], app(InternalTalent::class)->forVacancy($vacancy, $manager));
        $this->assertCount(1, app(InternalTalent::class)->forVacancy($vacancy, $owner));

        $application = new Application;
        $application->forceFill(['team_id' => $team->id, 'vacancy_id' => $vacancy->id, 'user_id' => $openUser->id, 'stage' => 'new'])->save();
        $this->assertSame([], app(InternalTalent::class)->forVacancy($vacancy, $owner));
    }

    public function test_telling_someone_about_a_role_is_once_by_a_recruiter_and_only_for_open_roles(): void
    {
        $owner = $this->owner();
        $team = $owner->currentTeam;
        $staff = $this->memberOf($owner, 'viewer');
        $person = $this->person($team, $staff, ['first_name' => 'Amahle']);
        $this->cert($team->id, $person->id, 'Forklift licence');
        $draft = $this->role($owner, 'Forklift licence', publish: false);
        $vacancy = $this->role($owner, 'Forklift licence');

        $this->actingAs($owner)->post(route('hiring.internal.invite', [$draft, $person]))->assertSessionHas('error');
        $this->actingAs($staff)->post(route('hiring.internal.invite', [$vacancy, $person]))->assertForbidden();
        $this->actingAs($owner)->post(route('hiring.internal.invite', [$vacancy, $person]))->assertSessionHas('status');
        $this->actingAs($owner)->post(route('hiring.internal.invite', [$vacancy, $person]))->assertSessionHas('error');

        $this->assertSame(1, InternalInvitation::query()->count());
        $this->assertSame(1, $staff->notifications()->count());
        $this->assertContains('vacancy.internal_invite', HrAuditLog::withoutTeamScope(fn () => HrAuditLog::query()->pluck('action')->all()));
        $this->actingAs($owner)->get(route('hiring.show', $vacancy))->assertSee('Told about it');

        $stranger = $this->owner();
        $this->actingAs($stranger)->post(route('hiring.internal.invite', [$vacancy, $person]))->assertNotFound();
    }

    public function test_insights_are_admin_only_and_computed_from_records(): void
    {
        Carbon::setTestNow('2026-11-02 09:00:00');
        $owner = $this->owner();
        $team = $owner->currentTeam;
        $staff = $this->memberOf($owner, 'viewer');

        $this->person($team, null, ['start_date' => '2020-01-01']);
        $this->person($team, null, ['start_date' => '2020-01-01']);
        $this->person($team, null, ['start_date' => '2020-01-01']);
        $this->person($team, null, ['start_date' => '2026-06-01']);
        $this->person($team, null, ['start_date' => '2019-01-01', 'end_date' => '2026-09-30', 'status' => 'terminated']);

        $vacancy = $this->role($owner, 'Excel');
        $hiredUser = User::factory()->create();
        $application = new Application;
        $application->forceFill(['team_id' => $team->id, 'vacancy_id' => $vacancy->id, 'user_id' => $hiredUser->id, 'stage' => 'hired', 'stage_changed_at' => now()])->save();
        $application->forceFill(['created_at' => now()->subDays(21)])->save();

        $this->actingAs($staff)->get(route('insights.index'))->assertForbidden();
        $response = $this->actingAs($owner)->get(route('insights.index'))->assertOk();

        // 4 active now; a year ago 4 were employed (three from 2020 and the leaver); 1 left → 25%.
        $workforce = $response->viewData('workforce');
        $this->assertSame(4, $workforce['active']);
        $this->assertSame(1, $workforce['joiners']);
        $this->assertSame(1, $workforce['leavers']);
        $this->assertSame(25.0, $workforce['turnover']);
        $this->assertSame(21, $response->viewData('recruitment')['medianDaysToHire']);
        $response->assertSee('Excel')->assertSee('nobody');
    }

    public function test_coverage_flags_single_holders_and_training_suggestions_point_at_open_roles(): void
    {
        $owner = $this->owner();
        $team = $owner->currentTeam;
        $position = new Position;
        $position->forceFill(['team_id' => $team->id, 'title' => 'Driver'])->save();
        $this->actingAs($owner)->post(route('requirements.store', $position), ['name' => 'Code 14 licence']);

        $amahle = $this->person($team, null, ['first_name' => 'Amahle', 'position_id' => $position->id]);
        $this->cert($team->id, $amahle->id, 'Code 14 licence');
        $this->cert($team->id, $amahle->id, 'Forklift licence');
        $this->role($owner, 'Forklift licence, Stock control');

        $coverage = $this->actingAs($owner)->get(route('insights.index'))->assertOk()->viewData('coverage');
        $bySkill = collect($coverage)->keyBy('skill');
        $this->assertCount(0, $bySkill['Stock control']['holders']);
        $this->assertCount(1, $bySkill['Code 14 licence']['holders']);
        $this->assertSame('Stock control', $coverage[0]['skill']);

        $this->actingAs($owner)->get(route('training.index'))->assertOk()
            ->assertSee('Could fill an open role with training')->assertSee('missing Stock control');
    }
}
