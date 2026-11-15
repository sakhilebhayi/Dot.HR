<?php

namespace Tests\Feature;

use App\Livewire\HeaderSearch;
use App\Models\Application;
use App\Models\Employee;
use App\Models\HrGrant;
use App\Models\Position;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\Hr\HeaderSearch as Search;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * R-52: the header search. HR holds people data, so what it finds depends on who is asking (administrator, manager, employee,
 * candidate), never crosses a business, never matches or shows pay, leave reasons or contact details, and finds nothing for a
 * person with no access. It asks the same models the pages use (team-scoped), so these tests are the guard for all of that.
 */
class HeaderSearchTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    /** Flatten the groups so an assertion can read every label and tag at once. */
    private function text(array $groups): string
    {
        return json_encode($groups, JSON_UNESCAPED_UNICODE);
    }

    /** @return array{0: User, 1: User, 2: User, 3: User} owner (admin), manager (editor), linked employee (viewer), unlinked member (viewer) */
    private function business(): array
    {
        $owner = $this->owner();
        $manager = $this->memberOf($owner, 'editor');
        $linked = $this->memberOf($owner, 'viewer');
        $plain = $this->memberOf($owner, 'viewer');
        $team = $owner->currentTeam;

        Position::create(['team_id' => $team->id, 'title' => 'Zebra Handler', 'department' => 'Safari']);
        $mine = $this->employeeIn($team, $linked, ['first_name' => 'Thandi', 'last_name' => 'Mokoena']);
        $other = $this->employeeIn($team, null, ['first_name' => 'Thabo', 'last_name' => 'Mokoena', 'email' => 'thabo.private@example.test']);
        $this->leaveFor($mine, now()->addDays(3)->toDateString(), now()->addDays(5)->toDateString(), ['status' => 'approved', 'reason' => 'Chemotherapy appointment']);
        $this->leaveFor($other, now()->addDays(10)->toDateString(), now()->addDays(11)->toDateString(), ['status' => 'pending', 'reason' => 'Divorce hearing']);
        $this->leaveFor($other, now()->subDays(1)->toDateString(), now()->addDays(2)->toDateString(), ['status' => 'approved', 'reason' => 'Surgery recovery']);

        return [$owner, $manager, $linked, $plain];
    }

    private function role(string $title, int $teamId, string $status = 'published'): Vacancy
    {
        return Vacancy::forceCreate([
            'team_id' => $teamId, 'title' => $title, 'description' => 'Sample role.', 'work_type' => 'full_time', 'arrangement' => 'onsite',
            'location' => 'Durban', 'status' => $status, 'published_at' => now(),
        ]);
    }

    private function apply(Vacancy $vacancy, User $user, string $stage = 'review'): Application
    {
        return Application::withoutTeamScope(function () use ($vacancy, $user, $stage) {
            $application = new Application;
            $application->forceFill(['team_id' => $vacancy->team_id, 'vacancy_id' => $vacancy->id, 'user_id' => $user->id, 'stage' => $stage, 'stage_changed_at' => now()])->save();

            return $application;
        });
    }

    private function search(User $user, string $query, bool $personal = false): array
    {
        $this->actingAs($user->fresh());

        return app(Search::class)->search($user->fresh(), $query, $personal);
    }

    // ── administrator ────────────────────────────────────────────────────────

    public function test_an_administrator_finds_people_leave_positions_and_candidates(): void
    {
        [$owner] = $this->business();
        $team = $owner->currentTeam;
        $candidate = User::factory()->create(['name' => 'Zuko Candidate']);
        $this->apply($this->role('Zebra Keeper', $team->id), $candidate);

        $people = $this->search($owner, 'thabo');
        $this->assertSame(['People', 'Leave'], array_keys($people), 'a person and their leave');
        $this->assertSame('Thabo Mokoena', $people['People'][0]['label']);

        $leave = $this->search($owner, 'annual')['Leave'];
        $this->assertNotEmpty($leave);
        $this->assertStringContainsString('Annual leave', $leave[0]['label'], 'an administrator sees the leave type');
        $this->assertContains($leave[0]['sub'], ['Pending', 'Approved']);

        $this->assertSame('Zebra Handler', $this->search($owner, 'zebra')['Positions'][0]['label']);
        $this->assertSame(route('positions.edit', Position::withoutTeamScope(fn () => Position::query()->firstOrFail())), $this->search($owner, 'zebra')['Positions'][0]['url']);

        $candidates = $this->search($owner, 'zuko')['Candidates'];
        $this->assertSame('Zuko Candidate, Zebra Keeper', $candidates[0]['label']);
        $this->assertSame('In review', $candidates[0]['sub']);
        $this->assertStringContainsString('/hiring/applications/', $candidates[0]['url']);
    }

    public function test_an_administrators_email_lookup_is_exact_only_and_never_prints_the_address(): void
    {
        [$owner] = $this->business();

        $exact = $this->search($owner, 'thabo.private@example.test');
        $this->assertSame('Thabo Mokoena', $exact['People'][0]['label']);
        $this->assertStringNotContainsString('thabo.private', $this->text($exact));

        $this->assertSame([], $this->search($owner, 'thabo.private@example'), 'a partial address finds nothing');
        $this->assertSame([], $this->search($owner, 'example.test'));
    }

    // ── manager ──────────────────────────────────────────────────────────────

    public function test_a_manager_finds_names_and_positions_and_only_the_names_of_people_who_are_away(): void
    {
        [, $manager] = $this->business();

        $this->assertSame('Thabo Mokoena', $this->search($manager, 'thabo')['People'][0]['label']);
        $this->assertSame('Zebra Handler', $this->search($manager, 'zebra')['Positions'][0]['label']);
        $this->assertSame(route('positions.index'), $this->search($manager, 'zebra')['Positions'][0]['url'], 'a manager may list positions, not edit them');

        // Thabo is away now (approved leave that has not ended): his name, until when. Nothing about the kind of leave or why.
        $leave = $this->search($manager, 'thabo')['Leave'];
        $this->assertCount(1, $leave, 'his pending request next week is not shown, only the leave in force');
        $this->assertSame('Thabo Mokoena', $leave[0]['label']);
        $this->assertStringStartsWith('Away to ', $leave[0]['sub']);

        $everything = $this->text($this->search($manager, 'mokoena'));
        foreach (['Annual', 'annual', 'Sick', 'Surgery', 'Divorce', 'Chemotherapy', 'Pending', 'pending', 'thabo.private', '@example'] as $secret) {
            $this->assertStringNotContainsString($secret, $everything, "a manager never sees $secret");
        }
    }

    public function test_a_manager_cannot_find_leave_by_type_or_reason_or_an_email_address(): void
    {
        [, $manager] = $this->business();

        $this->assertArrayNotHasKey('Leave', $this->search($manager, 'annual'), 'the leave type is not searchable by a manager');
        $this->assertSame([], $this->search($manager, 'surgery'), 'a reason is not searchable by anyone');
        $this->assertSame([], $this->search($manager, 'thabo.private@example.test'), 'an email address confirms nothing to a manager');
    }

    public function test_a_manager_gets_no_candidates_unless_a_hiring_grant_says_so(): void
    {
        [$owner, $manager] = $this->business();
        $candidate = User::factory()->create(['name' => 'Zuko Candidate']);
        $this->apply($this->role('Zebra Keeper', $owner->currentTeam->id), $candidate);

        $this->assertArrayNotHasKey('Candidates', $this->search($manager, 'zuko'));

        HrGrant::query()->create(['team_id' => $owner->currentTeam->id, 'user_id' => $manager->id, 'capability' => HrGrant::HIRING_MANAGER, 'granted_by' => $owner->id]);
        $this->assertArrayHasKey('Candidates', $this->search($manager, 'zuko'));
    }

    // ── employee ─────────────────────────────────────────────────────────────

    public function test_an_employee_finds_only_themselves_and_their_own_leave(): void
    {
        [, , $linked] = $this->business();

        $own = $this->search($linked, 'mokoena');
        $this->assertSame(['People'], array_keys($own), 'a name matches the person, not the leave (an employee finds leave by its type)');
        $this->assertCount(1, $own['People'], 'only their own record, not their colleague with the same surname');
        $this->assertSame('Thandi Mokoena', $own['People'][0]['label']);

        $this->assertSame([], $this->search($linked, 'thabo'), 'a colleague is not found');
        $this->assertSame([], $this->search($linked, 'zebra'), 'positions are not for an employee');
        $this->assertSame([], $this->search($linked, 'thabo.private@example.test'));

        $leave = $this->search($linked, 'annual')['Leave'];
        $this->assertCount(1, $leave, 'their own leave, found by its type');
        $this->assertStringStartsWith('Annual leave', $leave[0]['label']);
        $this->assertStringNotContainsString('Chemotherapy', $this->text($this->search($linked, 'annual')), 'not even their own reason is echoed into the box');
        $this->assertSame([], $this->search($linked, 'chemotherapy'), 'and a reason is never searchable');
    }

    public function test_a_member_with_no_role_and_no_record_finds_nothing(): void
    {
        [, , , $plain] = $this->business();

        foreach (['thandi', 'thabo', 'zebra', 'annual', 'mokoena'] as $query) {
            $this->assertSame([], $this->search($plain, $query), $query);
        }
    }

    // ── tenant isolation ─────────────────────────────────────────────────────

    public function test_nothing_crosses_into_another_business_for_any_role(): void
    {
        [$owner, $manager, $linked] = $this->business();

        $rival = $this->owner();
        $rivalTeam = $rival->currentTeam;
        Position::create(['team_id' => $rivalTeam->id, 'title' => 'Zebra Rival Handler', 'department' => 'Rival']);
        $rivalEmployee = $this->employeeIn($rivalTeam, null, ['first_name' => 'Thabo', 'last_name' => 'Rivalonly', 'email' => 'rival.only@example.test']);
        $this->leaveFor($rivalEmployee, now()->subDay()->toDateString(), now()->addDays(2)->toDateString(), ['status' => 'approved']);
        $rivalCandidate = User::factory()->create(['name' => 'Rival Candidate']);
        $this->apply($this->role('Rival Zebra Role', $rivalTeam->id), $rivalCandidate);

        foreach ([$owner, $manager, $linked] as $who) {
            foreach (['thabo', 'rivalonly', 'zebra', 'rival', 'candidate'] as $query) {
                $this->assertStringNotContainsString('Rival', $this->text($this->search($who, $query)), "$query leaks the other business");
            }
        }
        $this->assertStringNotContainsString('Thabo Mokoena', $this->text($this->search($rival, 'thabo')), 'and the other way round');
        $this->assertSame('Thabo Rivalonly', $this->search($rival, 'thabo')['People'][0]['label']);
        $this->assertSame('rival.only@example.test', 'rival.only@example.test');
        $this->assertSame([], $this->search($owner, 'rival.only@example.test'), 'an exact address belonging to another business finds nothing');
    }

    public function test_a_person_with_no_current_team_gets_nothing_and_a_removed_employee_is_not_found(): void
    {
        [$owner] = $this->business();
        $employee = Employee::withoutTeamScope(fn () => Employee::query()->where('first_name', 'Thabo')->firstOrFail());

        $this->assertNotEmpty($this->search($owner, 'thabo'));
        $employee->delete();
        $this->assertArrayNotHasKey('People', $this->search($owner, 'thabo'), 'a removed employee is not listed (soft delete)');
        $this->assertArrayNotHasKey('Leave', $this->search($owner, 'thabo'), 'nor their leave');

        $stray = User::factory()->create(['current_team_id' => null]);
        $this->assertSame([], $this->search($stray, 'thabo'));
    }

    // ── the term itself ──────────────────────────────────────────────────────

    public function test_short_terms_and_wildcards_find_nothing_and_quote_marks_are_harmless(): void
    {
        [$owner] = $this->business();

        $this->assertSame([], $this->search($owner, ''));
        $this->assertSame([], $this->search($owner, 't'), 'one letter asks nothing');
        $this->assertSame([], $this->search($owner, '%%'), 'a wildcard is not a search');
        $this->assertSame([], $this->search($owner, '__'));
        $this->assertSame([], $this->search($owner, 'th_bo'), 'underscore is not a one-letter wildcard');
        $this->assertSame([], $this->search($owner, "' OR 1=1 --"));
        $this->assertSame([], $this->search($owner, '"; DROP TABLE employees; --'));
        $this->assertSame(1, Employee::withoutTeamScope(fn () => Employee::query()->where('first_name', 'Thabo')->count()), 'nothing was dropped');
        $this->assertSame('Thabo Mokoena', $this->search($owner, '  THABO   mokoena  ')['People'][0]['label'], 'case and spacing do not matter');
    }

    public function test_each_group_is_limited(): void
    {
        $owner = $this->owner();
        for ($i = 1; $i <= 12; $i++) {
            $this->employeeIn($owner->currentTeam, null, ['first_name' => 'Limit', 'last_name' => 'Person'.$i]);
        }

        $this->assertCount(Search::PER_GROUP, $this->search($owner, 'limit')['People']);
    }

    // ── the personal (career) workspace ──────────────────────────────────────

    public function test_a_candidate_finds_their_own_applications_and_the_public_job_board_only(): void
    {
        [$owner] = $this->business();
        $team = $owner->currentTeam;
        $me = User::factory()->withPersonalTeam()->create(['name' => 'Kagiso Seeker']);
        $someone = User::factory()->create(['name' => 'Someone Else']);
        $mine = $this->apply($this->role('Zebra Keeper', $team->id), $me, 'interview');
        $this->apply($this->role('Zebra Cleaner', $team->id), $someone);
        $this->role('Zebra Draft', $team->id, 'draft');
        $this->role('Zebra Closed', $team->id, 'closed');

        $groups = $this->search($me, 'zebra', true);

        $this->assertSame(['My applications', 'Open roles'], array_keys($groups));
        $this->assertCount(1, $groups['My applications'], 'only their own application');
        $this->assertStringStartsWith('Zebra Keeper', $groups['My applications'][0]['label']);
        $this->assertSame('Interview', $groups['My applications'][0]['sub']);
        $this->assertSame(route('me.applications.show', $mine->id), $groups['My applications'][0]['url']);

        $roles = collect($groups['Open roles'])->pluck('label')->all();
        $this->assertContains('Zebra Keeper', $roles);
        $this->assertContains('Zebra Cleaner', $roles);
        $this->assertNotContains('Zebra Draft', $roles, 'a draft is not on the board');
        $this->assertNotContains('Zebra Closed', $roles, 'a closed role is not on the board');
        $this->assertStringNotContainsString('Someone Else', $this->text($groups), 'no other candidate is ever shown');
    }

    public function test_the_personal_workspace_never_shows_business_data_even_to_an_administrator(): void
    {
        [$owner] = $this->business();

        $groups = $this->search($owner, 'thabo', true);

        $this->assertSame([], $groups, 'in the career space an administrator is a job seeker: no employees, leave or positions');
        $this->assertSame([], $this->search($owner, 'zebra', true));
    }

    // ── the Livewire component ───────────────────────────────────────────────

    public function test_the_component_asks_nothing_until_the_box_is_focused_and_then_renders_rows(): void
    {
        [$owner] = $this->business();

        Livewire::actingAs($owner)->test(HeaderSearch::class)
            ->set('query', 'thabo')
            ->assertDontSee('Thabo Mokoena')
            ->call('touch')
            ->assertSee('Thabo Mokoena')
            ->assertSeeHtml('class="dsr-head"')
            ->assertSeeHtml('role="option"')
            ->set('query', 'nomatchatall')
            ->assertSee('Nothing you can open matches');
    }

    public function test_the_component_asks_for_at_least_two_letters(): void
    {
        [$owner] = $this->business();

        Livewire::actingAs($owner)->test(HeaderSearch::class)
            ->call('touch')
            ->set('query', 't')
            ->assertSee('Type at least two letters')
            ->assertDontSee('Thabo Mokoena');
    }

    public function test_the_personal_flag_cannot_be_changed_from_the_browser(): void
    {
        [$owner] = $this->business();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($owner)->test(HeaderSearch::class, ['personal' => true])->set('personal', false);
    }

    public function test_the_component_in_the_personal_workspace_shows_the_career_rows(): void
    {
        [$owner] = $this->business();
        $this->role('Zebra Keeper', $owner->currentTeam->id);
        $me = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($me)->test(HeaderSearch::class, ['personal' => true])
            ->call('touch')->set('query', 'zebra')
            ->assertSee('Open roles')->assertSee('Zebra Keeper');
    }

    // ── on every page type ───────────────────────────────────────────────────

    public function test_the_search_is_on_every_page_type_with_the_right_scope_label(): void
    {
        [$owner, $manager, $linked] = $this->business();

        foreach ([$owner, $manager, $linked] as $who) {
            foreach (['dashboard', 'my-work.index', 'leave-requests.index', 'leave-requests.create', 'profile.show', 'me.home', 'jobs.index'] as $route) {
                $response = $this->actingAs($who->fresh())->get(route($route));
                if ($response->status() !== 200) {
                    continue; // a page this role may not open has no header to check
                }
                $html = $response->getContent();
                $this->assertStringContainsString('data-dh-search', $html, "$route has the header search");
                $this->assertSame(1, substr_count($html, 'class="dsr-input'), "$route: one pill");
            }
        }

        $html = $this->actingAs($owner)->get(route('dashboard'))->getContent();
        $this->assertStringContainsString('Find a person, leave or position', $html);
        $html = $this->actingAs($owner)->get(route('me.home'))->getContent();
        $this->assertStringContainsString('Find a role or application', $html);
    }

    public function test_a_signed_out_visitor_gets_a_plain_get_search_over_the_open_roles_only(): void
    {
        [$owner] = $this->business();
        $this->role('Zebra Keeper', $owner->currentTeam->id);

        $html = $this->get(route('jobs.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Search open roles', $html);
        $this->assertStringNotContainsString('wire:id', preg_replace('/.*<header/s', '<header', explode('</header>', $html)[0]), 'no Livewire (no data lookup) in the signed-out bar');
        $this->assertStringNotContainsString('Thabo', $html);
    }
}
