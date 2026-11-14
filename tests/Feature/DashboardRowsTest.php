<?php

namespace Tests\Feature;

use App\Models\Position;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * The dashboard as ruled rows (R-51): the queue and its selected-record pane, the tally,
 * the privacy of each role and the honest empty states. Every figure is a count of records.
 */
class DashboardRowsTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    private function tally(TestResponse $response): string
    {
        $this->assertSame(1, preg_match('/<div class="dash-tally"[^>]*>(.*?)<\/div>/s', $response->getContent(), $m));

        return trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
    }

    public function test_the_admin_queue_rows_carry_the_facts_the_pane_shows_and_the_reason_only_for_admins(): void
    {
        $owner = $this->owner();
        $team = $owner->currentTeam;
        $person = $this->employeeIn($team, null, ['first_name' => 'Quorvath', 'last_name' => 'Zelmarsh']);
        $request = $this->leaveFor($person, now()->addDays(5), now()->addDays(6), ['reason' => 'wedding in the family']);

        $html = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-queue', $html);
        $this->assertStringContainsString('role="option" aria-selected="true"', $html);
        $this->assertStringContainsString('data-name="Quorvath Zelmarsh"', $html);
        $this->assertStringContainsString('data-reason="wedding in the family"', $html);
        $this->assertStringContainsString('data-href="'.route('leave-requests.show', $request).'"', $html);
        $this->assertSame(1, substr_count($html, 'data-primary-action'));
    }

    public function test_the_queue_is_tenant_scoped_and_empty_says_so(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $stranger = $this->employeeIn($other->currentTeam, null, ['first_name' => 'Outsider', 'last_name' => 'Elsewhere']);
        $this->leaveFor($stranger, now()->addDays(3), now()->addDays(4), ['reason' => 'someone else reason']);
        $this->employeeIn($owner->currentTeam);

        $response = $this->actingAs($owner)->get(route('dashboard'))->assertOk()
            ->assertSee('No pending leave requests.')
            ->assertDontSee('Outsider')
            ->assertDontSee('someone else reason')
            ->assertDontSee('data-queue-pane', false);

        $this->assertStringNotContainsString('data-queue', $response->getContent());
        $this->assertStringContainsString('nothing is waiting on you', $response->getContent());
    }

    public function test_the_tally_counts_match_the_records(): void
    {
        $owner = $this->owner();
        $team = $owner->currentTeam;
        $away = $this->employeeIn($team, null, ['first_name' => 'Awaylee', 'last_name' => 'Todaysen']);
        $this->employeeIn($team);
        $this->employeeIn($team, null, ['status' => 'terminated']);
        Position::create(['team_id' => $team->id, 'title' => 'Driver', 'department' => 'Fleet']);
        Position::create(['team_id' => $team->id, 'title' => 'Clerk', 'department' => 'Office']);
        $this->leaveFor($away, now()->subDay(), now()->addDay(), ['status' => 'approved']);

        $response = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('Awaylee Todaysen');

        $this->assertSame('2 employed 1 away today 2 positions', $this->tally($response));
    }

    public function test_a_manager_sees_names_and_dates_only_and_the_privacy_panel(): void
    {
        $owner = $this->owner();
        $team = $owner->currentTeam;
        $manager = $this->memberOf($owner, 'editor');
        $away = $this->employeeIn($team, null, ['first_name' => 'Quorvath', 'last_name' => 'Zelmarsh', 'phone' => '+27 83 999 8888']);
        $this->leaveFor($away, now()->subDay(), now()->addDay(), [
            'status' => 'approved', 'type' => 'sick', 'leave_type_id' => $this->leaveType($team, 'sick')->id, 'reason' => 'private medical note',
        ]);

        $response = $this->actingAs($manager)->get(route('dashboard'))->assertOk()
            ->assertSee('Quorvath Zelmarsh')
            ->assertSee('What you can see here')
            ->assertSee('Not shown to managers')
            ->assertDontSee('Sick')
            ->assertDontSee('private medical note')
            ->assertDontSee('+27 83 999 8888')
            ->assertDontSee('data-queue', false)
            ->assertDontSee('data-reason', false);

        $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action'));
        // The positions figure is not a link for a manager.
        $this->assertStringNotContainsString(route('positions.index'), $this->tallyHtml($response));
    }

    private function tallyHtml(TestResponse $response): string
    {
        preg_match('/<div class="dash-tally"[^>]*>(.*?)<\/div>/s', $response->getContent(), $m);

        return $m[1] ?? '';
    }

    public function test_an_employee_sees_only_their_own_balance_and_leave(): void
    {
        $owner = $this->owner();
        $team = $owner->currentTeam;
        $staff = $this->memberOf($owner, 'viewer');
        $mine = $this->employeeIn($team, $staff, ['first_name' => 'Lerato', 'last_name' => 'Nkosi']);
        $other = $this->employeeIn($team, null, ['first_name' => 'Quorvath', 'last_name' => 'Zelmarsh']);
        $this->leaveFor($mine, now()->addDays(10), now()->addDays(11));
        $this->leaveFor($other, now()->addDays(10), now()->addDays(11), ['reason' => 'not yours']);

        $response = $this->actingAs($staff->fresh())->get(route('dashboard'))->assertOk()
            ->assertSee('Your balance')
            ->assertSee('Coming up')
            ->assertDontSee('Quorvath')
            ->assertDontSee('not yours')
            ->assertDontSee('data-queue', false);

        $this->assertSame('0 steps for you 1 request pending', $this->tally($response));
        $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action'));
    }

    public function test_a_member_with_no_record_sees_nothing_about_anyone(): void
    {
        $owner = $this->owner();
        $this->employeeIn($owner->currentTeam, null, ['first_name' => 'Quorvath', 'last_name' => 'Zelmarsh']);
        $newcomer = $this->memberOf($owner, 'viewer');

        $this->actingAs($newcomer)->get(route('dashboard'))->assertOk()
            ->assertSee('Two ways it gets linked')
            ->assertDontSee('Quorvath')
            ->assertDontSee('dash-tally', false);
    }

    public function test_the_dashboard_animation_is_calm_and_switched_off_for_reduced_motion(): void
    {
        $css = (string) file_get_contents(resource_path('css/dashboard.css'));

        $this->assertStringContainsString('prefers-reduced-motion: reduce', $css);
        $this->assertStringContainsString('animation: none !important', $css);
        // Nothing here animates a number: no counter keyframes, and the pane settles by fade only.
        $this->assertDoesNotMatchRegularExpression('/@keyframes\s+\S*(count|tick|spin|pulse)/i', $css);
        $this->assertMatchesRegularExpression('/\.dash-pane-body\.is-settling\s*\{\s*animation:\s*dash-fade/', $css);
        $this->assertStringNotContainsString('<script', (string) file_get_contents(resource_path('views/dashboard.blade.php')));
    }

    public function test_the_candidate_home_shows_the_profile_pane_and_one_primary_action(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('me.home'))->assertOk()
            ->assertSee('Your applications')
            ->assertSee('Nothing in progress.')
            ->assertSee('Your email address and phone number are never part of your profile.');

        $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action'));
        $this->assertStringContainsString('dash-tally', $response->getContent());
    }
}
