<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Employee;
use App\Models\HrAuditLog;
use App\Models\HrGrant;
use App\Models\LifecycleChecklist;
use App\Models\Offer;
use App\Models\Position;
use App\Models\ProfessionalProfile;
use App\Models\User;
use App\Models\Vacancy;
use App\Notifications\CandidateUpdateNotification;
use App\Notifications\NewApplicationNotification;
use App\Services\Recruitment\HireFromOffer;
use App\Services\Recruitment\SkillMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * R-32 Phase 4: the recruitment marketplace, end to end (brief §23 "Recruitment"): create,
 * publish, discover, apply, review, move, interview, offer, accept, onboard — plus the
 * isolation and reliability rules around it.
 */
class RecruitmentTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    /** Drop whoever a previous request signed in, so the next request is a stranger's. */
    private function asGuest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function rolePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Bookkeeper',
            'description' => 'Keep the books of a growing wholesaler.',
            'skills' => 'Sage, Pastel Partner, payroll, VAT returns',
            'work_type' => 'full_time',
            'arrangement' => 'on_site',
            'location' => 'Mahikeng',
            'pay_basis' => 'salary',
            'pay_min' => '18000',
            'pay_max' => '22000',
            'currency' => 'ZAR',
            'openings' => 1,
            'closes_on' => now()->addWeeks(2)->toDateString(),
            'screening_questions' => "How many years with Pastel?\nCan you start in December?",
        ], $overrides);
    }

    private function publishedRole(User $recruiter, array $overrides = []): Vacancy
    {
        $this->actingAs($recruiter)->post(route('hiring.store'), $this->rolePayload($overrides))->assertRedirect();
        $vacancy = Vacancy::query()->latest('id')->firstOrFail();
        $this->actingAs($recruiter)->post(route('hiring.transition', [$vacancy, 'publish']))->assertRedirect();

        return $vacancy->fresh();
    }

    private function candidate(array $skills = ['Pastel Partner', 'payroll']): User
    {
        $person = User::factory()->withPersonalTeam()->create(['name' => 'Kagiso Mahlangu']);
        $profile = ProfessionalProfile::forUser($person);
        foreach ($skills as $i => $skill) {
            $profile->skills()->create(['name' => $skill, 'normalized' => SkillMatch::normalise($skill), 'position' => $i]);
        }

        return $person;
    }

    private function apply(User $candidate, Vacancy $vacancy): Application
    {
        $this->actingAs($candidate)->post(route('jobs.apply', $vacancy->id), [
            'answers' => ['Six years', 'Yes'],
            'cover_note' => 'I keep books for a living.',
            'share' => '1',
        ])->assertRedirect();

        return Application::withoutTeamScope(fn () => Application::query()->where('vacancy_id', $vacancy->id)->where('user_id', $candidate->id)->firstOrFail());
    }

    public function test_the_whole_journey_from_role_to_employee_record(): void
    {
        Notification::fake();
        $owner = $this->owner();
        $vacancy = $this->publishedRole($owner);

        // Discovered on the public board without an account.
        $this->asGuest();
        $this->get(route('jobs.index', ['q' => 'pastel']))->assertOk()->assertSee('Bookkeeper')->assertSee('R18 000 – R22 000 a month');
        $this->get(route('jobs.show', $vacancy->id))->assertOk()->assertSee('Sign in to apply');

        $candidate = $this->candidate();
        $this->actingAs($candidate)->get(route('jobs.show', $vacancy->id))->assertSee('matches 2 of 4 skills: Pastel Partner, payroll');

        $application = $this->apply($candidate, $vacancy);
        $this->assertSame('new', $application->stage);
        Notification::assertSentTo($owner, NewApplicationNotification::class);

        // The employer reviews it — with the explainable match and the shared email.
        $this->actingAs($owner)->get(route('hiring.applications.show', $application))
            ->assertOk()->assertSee('Six years')->assertSee($candidate->email)->assertSee('matches 2 of 4 skills');

        $this->actingAs($owner)->post(route('hiring.applications.move', $application), ['stage' => 'shortlisted'])->assertRedirect();
        $this->actingAs($owner)->post(route('hiring.applications.interviews.store', $application), [
            'starts_at' => now()->addDays(3)->setTime(10, 0)->toDateTimeString(),
            'duration_minutes' => 45, 'mode' => 'in_person', 'where' => '12 Main Rd, Mahikeng',
            'candidate_note' => 'Bring your ID.',
        ])->assertRedirect();
        $this->assertSame('interview', $application->fresh()->stage);
        Notification::assertSentTo($candidate, CandidateUpdateNotification::class);

        $this->actingAs($owner)->post(route('hiring.applications.offers.store', $application), [
            'job_title' => 'Bookkeeper', 'pay_basis' => 'salary', 'pay_amount' => '20000', 'currency' => 'ZAR',
            'starts_on' => now()->addMonth()->toDateString(), 'terms' => 'Three months probation.', 'send' => '1',
        ])->assertRedirect();
        $this->assertSame('offer', $application->fresh()->stage);

        // The candidate sees the plain view: interview details and the offer, never notes.
        $this->actingAs($candidate)->get(route('me.applications.show', $application->id))
            ->assertOk()->assertSee('Offer made')->assertSee('Bring your ID.')->assertSee('R20 000.00 a month');

        $this->actingAs($candidate)->post(route('me.applications.respond', [$application->id, 'accept']))->assertRedirect();

        $application = $application->fresh();
        $this->assertSame('hired', $application->stage);

        $employee = Employee::withoutTeamScope(fn () => Employee::query()->where('team_id', $owner->currentTeam->id)->where('user_id', $candidate->id)->firstOrFail());
        $this->assertSame('Kagiso', $employee->first_name);
        $this->assertSame('Mahlangu', $employee->last_name);
        $this->assertSame('full_time', $employee->employment_type);

        // Recruitment flows straight into onboarding (brief §25.6).
        $this->assertTrue(LifecycleChecklist::withoutTeamScope(fn () => LifecycleChecklist::query()
            ->where('employee_id', $employee->id)->where('process', 'onboarding')->where('status', 'open')->exists()));
        $this->assertSame('Bookkeeper', Position::withoutTeamScope(fn () => Position::query()->find($employee->position_id))->title);
    }

    public function test_accepting_twice_or_racing_creates_exactly_one_employee(): void
    {
        $owner = $this->owner();
        $vacancy = $this->publishedRole($owner);
        $candidate = $this->candidate();
        $application = $this->apply($candidate, $vacancy);

        $this->actingAs($owner)->post(route('hiring.applications.offers.store', $application), [
            'job_title' => 'Bookkeeper', 'pay_basis' => 'salary', 'pay_amount' => '20000', 'currency' => 'ZAR',
            'starts_on' => now()->addMonth()->toDateString(), 'send' => '1',
        ]);
        $offer = Offer::withoutTeamScope(fn () => Offer::query()->firstOrFail());

        $first = app(HireFromOffer::class)->accept($offer, $candidate);
        $second = app(HireFromOffer::class)->accept($offer->fresh(), $candidate);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Employee::withoutTeamScope(fn () => Employee::query()->where('user_id', $candidate->id)->count()));

        $this->expectException(\RuntimeException::class);
        app(HireFromOffer::class)->accept($offer->fresh(), User::factory()->create());
    }

    public function test_a_hire_links_an_existing_unlinked_record_with_the_same_email(): void
    {
        $owner = $this->owner();
        $candidate = $this->candidate();
        $manual = $this->employeeIn($owner->currentTeam, null, ['email' => $candidate->email, 'first_name' => 'K.', 'last_name' => 'Mahlangu']);

        $vacancy = $this->publishedRole($owner);
        $application = $this->apply($candidate, $vacancy);
        $this->actingAs($owner)->post(route('hiring.applications.offers.store', $application), [
            'job_title' => 'Bookkeeper', 'pay_basis' => 'undisclosed', 'currency' => 'ZAR',
            'starts_on' => now()->addMonth()->toDateString(), 'send' => '1',
        ]);
        $this->actingAs($candidate)->post(route('me.applications.respond', [$application->id, 'accept']));

        $this->assertSame($candidate->id, $manual->fresh()->user_id);
        $this->assertSame(1, Employee::withoutTeamScope(fn () => Employee::query()->where('team_id', $owner->currentTeam->id)->where('user_id', $candidate->id)->count()));
    }

    public function test_applying_twice_is_one_application_and_the_share_consent_is_required(): void
    {
        $owner = $this->owner();
        $vacancy = $this->publishedRole($owner);
        $candidate = $this->candidate();

        $this->actingAs($candidate)->post(route('jobs.apply', $vacancy->id), ['answers' => ['a', 'b']])
            ->assertSessionHasErrors('share');

        $this->apply($candidate, $vacancy);
        $this->actingAs($candidate)->post(route('jobs.apply', $vacancy->id), ['answers' => ['a', 'b'], 'share' => '1'])
            ->assertRedirect()->assertSessionHas('status', 'You have already applied for this role.');

        $this->assertSame(1, Application::withoutTeamScope(fn () => Application::query()->count()));
    }

    public function test_drafts_paused_closed_and_expired_roles_are_invisible_and_unappliable(): void
    {
        $owner = $this->owner();
        $candidate = $this->candidate();

        $this->actingAs($owner)->post(route('hiring.store'), $this->rolePayload(['title' => 'Draft role']));
        $draft = Vacancy::query()->latest('id')->firstOrFail();
        $this->asGuest();

        $this->get(route('jobs.show', $draft->id))->assertNotFound();
        $this->actingAs($candidate)->post(route('jobs.apply', $draft->id), ['answers' => ['a', 'b'], 'share' => '1'])->assertNotFound();

        $live = $this->publishedRole($owner, ['title' => 'Paused role']);
        $this->actingAs($owner)->post(route('hiring.transition', [$live, 'pause']));
        $this->asGuest();
        $this->get(route('jobs.show', $live->id))->assertNotFound();
        $this->get(route('jobs.index'))->assertDontSee('Paused role');

        $expired = $this->publishedRole($owner, ['title' => 'Expired role']);
        Vacancy::withoutTeamScope(fn () => $expired->forceFill(['closes_on' => now()->subDay()])->save());
        $this->asGuest();
        $this->get(route('jobs.show', $expired->id))->assertNotFound();
    }

    public function test_one_business_never_sees_another_businesss_roles_candidates_or_notes(): void
    {
        $ownerA = $this->owner();
        $ownerB = $this->owner();
        $vacancy = $this->publishedRole($ownerA);
        $candidate = $this->candidate();
        $application = $this->apply($candidate, $vacancy);

        $this->actingAs($ownerA)->post(route('hiring.applications.note', $application), ['body' => 'Strong on VAT, ask about payroll.']);

        $this->actingAs($ownerB)->get(route('hiring.show', $vacancy))->assertNotFound();
        $this->actingAs($ownerB)->get(route('hiring.applications.show', $application))->assertNotFound();
        $this->actingAs($ownerB)->post(route('hiring.applications.move', $application), ['stage' => 'rejected'])->assertNotFound();
        $this->actingAs($ownerB)->get(route('hiring.index'))->assertDontSee('Bookkeeper');

        $this->assertSame('new', $application->fresh()->stage);

        // The candidate never sees the note or the internal history either.
        $this->actingAs($candidate)->get(route('me.applications.show', $application->id))
            ->assertOk()->assertDontSee('Strong on VAT');
    }

    public function test_candidates_only_reach_their_own_applications(): void
    {
        $owner = $this->owner();
        $vacancy = $this->publishedRole($owner);
        $mine = $this->apply($this->candidate(), $vacancy);
        $other = User::factory()->withPersonalTeam()->create();

        $this->actingAs($other)->get(route('me.applications.show', $mine->id))->assertNotFound();
        $this->actingAs($other)->post(route('me.applications.withdraw', $mine->id))->assertNotFound();
        $this->actingAs($other)->post(route('me.applications.respond', [$mine->id, 'accept']))->assertNotFound();
    }

    public function test_recruiting_needs_the_grant_and_hiring_managers_may_only_review(): void
    {
        $owner = $this->owner();
        $member = $this->memberOf($owner, 'viewer');
        $hiringManager = $this->memberOf($owner, 'editor');
        HrGrant::query()->create(['team_id' => $owner->currentTeam->id, 'user_id' => $hiringManager->id, 'capability' => HrGrant::HIRING_MANAGER]);

        $vacancy = $this->publishedRole($owner);
        $application = $this->apply($this->candidate(), $vacancy);

        $this->actingAs($member)->get(route('hiring.index'))->assertForbidden();
        $this->actingAs($member)->post(route('hiring.store'), $this->rolePayload())->assertForbidden();

        $this->actingAs($hiringManager)->get(route('hiring.applications.show', $application))->assertOk();
        $this->actingAs($hiringManager)->post(route('hiring.applications.note', $application), ['body' => 'Good fit.'])->assertRedirect();
        $this->actingAs($hiringManager)->post(route('hiring.applications.move', $application), ['stage' => 'rejected'])->assertForbidden();
        $this->actingAs($hiringManager)->post(route('hiring.transition', [$vacancy, 'close']))->assertForbidden();
    }

    public function test_applying_opens_an_applications_only_profile_to_that_business_and_no_other(): void
    {
        $ownerA = $this->owner();
        $ownerB = $this->owner();
        $vacancy = $this->publishedRole($ownerA);
        $candidate = $this->candidate();
        $profile = ProfessionalProfile::forUser($candidate);
        $profile->update(['visibility' => 'applications']);

        $this->actingAs($ownerA)->get(route('people.show', $profile->handle))->assertNotFound();

        $this->apply($candidate, $vacancy);

        $this->actingAs($ownerA)->get(route('people.show', $profile->handle))->assertOk();
        $this->actingAs($ownerB)->get(route('people.show', $profile->handle))->assertNotFound();
    }

    public function test_withdrawing_closes_the_application_and_any_live_offer(): void
    {
        $owner = $this->owner();
        $vacancy = $this->publishedRole($owner);
        $candidate = $this->candidate();
        $application = $this->apply($candidate, $vacancy);
        $this->actingAs($owner)->post(route('hiring.applications.offers.store', $application), [
            'job_title' => 'Bookkeeper', 'pay_basis' => 'undisclosed', 'currency' => 'ZAR',
            'starts_on' => now()->addMonth()->toDateString(), 'send' => '1',
        ]);

        $this->actingAs($candidate)->post(route('me.applications.withdraw', $application->id))->assertRedirect();

        $this->assertSame('withdrawn', $application->fresh()->stage);
        $this->assertSame('withdrawn', Offer::withoutTeamScope(fn () => Offer::query()->firstOrFail()->status));
        $this->actingAs($candidate)->post(route('me.applications.respond', [$application->id, 'accept']))->assertSessionHas('error');
        $this->assertSame(0, Employee::withoutTeamScope(fn () => Employee::query()->where('user_id', $candidate->id)->count()));
    }

    public function test_role_validation_requires_honest_pay_and_a_place_and_the_lifecycle_is_audited(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('hiring.store'), $this->rolePayload(['pay_min' => '', 'location' => '']))
            ->assertSessionHasErrors(['pay_min', 'location']);

        $this->actingAs($owner)->post(route('hiring.store'), $this->rolePayload(['pay_basis' => 'undisclosed', 'pay_min' => '', 'arrangement' => 'remote', 'location' => '']))
            ->assertRedirect();

        $vacancy = Vacancy::query()->latest('id')->firstOrFail();
        $this->assertNull($vacancy->pay_min);
        $this->assertSame('Not disclosed', $vacancy->payLabel());

        $this->actingAs($owner)->post(route('hiring.transition', [$vacancy, 'publish']));
        $this->actingAs($owner)->post(route('hiring.transition', [$vacancy, 'reopen']))->assertStatus(422);
        $this->actingAs($owner)->post(route('hiring.transition', [$vacancy, 'close']));
        $this->assertSame('closed', $vacancy->fresh()->status);

        $actions = HrAuditLog::withoutTeamScope(fn () => HrAuditLog::query()->pluck('action')->all());
        $this->assertContains('vacancy.publish', $actions);
        $this->assertContains('vacancy.close', $actions);
    }
}
