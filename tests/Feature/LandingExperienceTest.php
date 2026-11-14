<?php

namespace Tests\Feature;

use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * The signed-in landing (R-50): the page a person arrives at from InfoDot. It greets them
 * by first name in their company, carries exactly one primary action for their role, shows
 * the original logo, offers the way back to InfoDot, and prints nothing that is not a real
 * record. An ordinary employee never sees another person's leave on it.
 */
class LandingExperienceTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    /** The original artwork, as committed: the landing may present it, never change it. */
    private const LOGO_HASHES = [
        'images/logo.png' => 'e7a6489cb2821d50c1393a94fc933ba8c333a039410c80aabd48a5d85eb697f5',
        'images/logo-light.png' => 'a86bed2629a6bb57cfaabf7ed62a7117cde20835a3d515716af0cd440f29b50d',
        'images/logo-trim.png' => 'a0077c8fb9059cb704125f54bd4737e237f78d72e59369f86d2e7646193193f8',
        'images/logo-light-trim.png' => '076d4814167259e1061b1c69d7bec309f985e9f4fe8b6f6f6abcfd5faad07172',
    ];

    private function namedOwner(): User
    {
        $owner = $this->owner();
        $owner->forceFill(['name' => 'Naledi Mokoena'])->save();
        $owner->currentTeam->forceFill(['name' => 'Khumalo Freight'])->save();

        return $owner->fresh();
    }

    /** The href of the single element carrying the primary-action marker. */
    private function primaryHref(TestResponse $response): string
    {
        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, 'data-primary-action'), 'The landing carries exactly one primary action.');
        $this->assertSame(1, preg_match('/<a href="([^"]+)"[^>]*data-primary-action/', $html, $match));

        return html_entity_decode($match[1]);
    }

    /** The landing block itself, without the date line (the only digits it may carry besides counts). */
    private function landingText(TestResponse $response): string
    {
        $this->assertSame(1, preg_match('/<header class="rg-land">(.*?)<\/header>/s', $response->getContent(), $match));

        return trim(strip_tags(preg_replace('/<span class="dot-note rg-land-date">.*?<\/span>/s', '', $match[1])));
    }

    public function test_an_owner_with_leave_waiting_is_sent_to_decide_it(): void
    {
        $owner = $this->namedOwner();
        $team = $owner->currentTeam;
        $first = $this->employeeIn($team, null, ['first_name' => 'Quorvath', 'last_name' => 'Zelmarsh']);
        $second = $this->employeeIn($team);
        $this->employeeIn($team);
        Position::create(['team_id' => $team->id, 'title' => 'Driver', 'department' => 'Fleet']);
        $this->leaveFor($first, now()->addDays(5), now()->addDays(6));
        $this->leaveFor($second, now()->addDays(8), now()->addDays(9));

        $response = $this->actingAs($owner)->get(route('dashboard'))->assertOk();

        // The welcome is this person in this company.
        $response->assertSee('Naledi')->assertSee('Dot.HR is the staff register for Khumalo Freight', false);

        // One primary action, and it opens the leave waiting for a decision.
        $href = $this->primaryHref($response);
        $this->assertSame(route('leave-requests.index', ['status' => 'pending']), $href);
        $this->actingAs($owner)->get($href)->assertOk()->assertSee('Quorvath');

        // Every figure is a count of the seeded records: 2 pending, 3 employed, 1 position, nobody away.
        $this->assertStringContainsString('2 requests', $this->landingText($response));
        $this->assertSame(1, preg_match_all('/\d+/', $this->landingText($response)));
        preg_match_all('/<div class="dot-figure"[^>]*>(\d+)<\/div>/', $response->getContent(), $figures);
        $this->assertSame(['03', '00', '01'], $figures[1]);
    }

    public function test_an_owner_with_nothing_waiting_is_sent_to_add_an_employee(): void
    {
        $owner = $this->namedOwner();
        $this->employeeIn($owner->currentTeam);

        $response = $this->actingAs($owner)->get(route('dashboard'))->assertOk()
            ->assertSee('nothing is waiting on you')
            ->assertSee('Add an employee');

        $href = $this->primaryHref($response);
        $this->assertSame(route('employees.create'), $href);
        $this->actingAs($owner)->get($href)->assertOk();
    }

    public function test_a_new_business_gets_an_honest_empty_state_and_no_numbers(): void
    {
        $owner = $this->namedOwner();

        $response = $this->actingAs($owner)->get(route('dashboard'))->assertOk()
            ->assertSee('Nobody is on the register yet')
            ->assertSee('Add your first employee');

        $this->assertSame(route('employees.create'), $this->primaryHref($response));

        // Nothing has been recorded, so the landing states no figure at all.
        $this->assertSame(0, preg_match('/\d/', $this->landingText($response)));
        $this->assertStringNotContainsString('class="dot-figure"', $response->getContent());
    }

    public function test_a_manager_is_sent_to_the_teams_leave(): void
    {
        $owner = $this->namedOwner();
        $manager = $this->memberOf($owner, 'editor');
        $other = $this->employeeIn($owner->currentTeam, null, ['first_name' => 'Quorvath', 'last_name' => 'Zelmarsh']);
        $this->leaveFor($other, now()->subDay(), now()->addDay(), [
            'status' => 'approved',
            'type' => 'sick',
            'leave_type_id' => $this->leaveType($owner->currentTeam, 'sick')->id,
            'reason' => 'quorvath private reason',
        ]);

        $response = $this->actingAs($manager)->get(route('dashboard'))->assertOk()
            // A manager may know who is away, never why.
            ->assertSee('Quorvath Zelmarsh')
            ->assertDontSee('quorvath private reason')
            ->assertDontSee('Sick');

        $href = $this->primaryHref($response);
        $this->assertSame(route('leave-requests.index'), $href);
        $this->actingAs($manager)->get($href)->assertOk();
    }

    public function test_an_employee_is_sent_to_request_leave_and_sees_nobody_elses_leave(): void
    {
        $owner = $this->namedOwner();
        $team = $owner->currentTeam;
        $staff = $this->memberOf($owner, 'viewer');
        $staff->forceFill(['name' => 'Lerato Nkosi'])->save();
        $mine = $this->employeeIn($team, $staff, ['first_name' => 'Lerato', 'last_name' => 'Nkosi']);
        $other = $this->employeeIn($team, null, [
            'first_name' => 'Quorvath',
            'last_name' => 'Zelmarsh',
            'email' => 'quorvath.zelmarsh@example.test',
            'phone' => '+27 83 999 8888',
        ]);

        $this->leaveFor($mine, now()->addDays(10), now()->addDays(11), ['reason' => 'my own reason']);
        // Someone else is off sick today and has more leave waiting, each with a reason.
        $this->leaveFor($other, now()->subDay(), now()->addDay(), [
            'status' => 'approved',
            'type' => 'sick',
            'leave_type_id' => $this->leaveType($team, 'sick')->id,
            'reason' => 'quorvath medical note',
        ]);
        $this->leaveFor($other, now()->addDays(20), now()->addDays(22), ['reason' => 'quorvath private reason']);

        $response = $this->actingAs($staff->fresh())->get(route('dashboard'))->assertOk()
            ->assertSee('Lerato')
            ->assertSee('Dot.HR is where you ask Khumalo Freight for leave', false)
            ->assertSee('Your balance')
            // Not who else is away, not why, not how to reach them, not how many are waiting.
            ->assertDontSee('Quorvath')
            ->assertDontSee('Zelmarsh')
            ->assertDontSee('quorvath medical note')
            ->assertDontSee('quorvath private reason')
            ->assertDontSee('quorvath.zelmarsh@example.test')
            ->assertDontSee('+27 83 999 8888')
            ->assertDontSee('Needs a decision')
            ->assertDontSee('Away today')
            ->assertDontSee('waiting on you');

        $href = $this->primaryHref($response);
        $this->assertSame(route('leave-requests.create'), $href);
        $this->actingAs($staff->fresh())->get($href)->assertOk()->assertDontSee('Quorvath');
    }

    public function test_a_member_with_no_record_gets_the_empty_state_and_a_way_forward(): void
    {
        $owner = $this->namedOwner();
        $this->employeeIn($owner->currentTeam, null, ['first_name' => 'Quorvath', 'last_name' => 'Zelmarsh']);
        $newcomer = $this->memberOf($owner, 'viewer');

        $response = $this->actingAs($newcomer)->get(route('dashboard'))->assertOk()
            ->assertSee('Your account is not linked to an employee record yet')
            ->assertSee('Khumalo Freight')
            ->assertDontSee('Quorvath');

        $this->assertSame(0, preg_match('/\d/', $this->landingText($response)));

        $href = $this->primaryHref($response);
        $this->assertSame(route('me.home'), $href);
        $this->actingAs($newcomer)->get($href)->assertOk();
    }

    public function test_the_landing_shows_the_original_logo_files_unaltered(): void
    {
        foreach (self::LOGO_HASHES as $file => $hash) {
            $this->assertFileExists(public_path($file));
            $this->assertSame($hash, hash_file('sha256', public_path($file)), "$file is not the original artwork.");
        }

        $html = $this->actingAs($this->namedOwner())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<header class="rg-land">(.*?)<\/header>/s', $html, $match));
        $this->assertStringContainsString(asset('images/logo-trim.png'), $match[1]);
        $this->assertStringContainsString(asset('images/logo-light-trim.png'), $match[1]);
        $this->assertStringContainsString('alt="Dot.HR"', $match[1]);
    }

    public function test_the_landing_links_back_to_infodot_and_its_platforms(): void
    {
        $html = $this->actingAs($this->namedOwner())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('<a href="https://infodot.co.za/home">InfoDot</a>', $html);
        $this->assertStringContainsString('<a href="https://infodot.co.za/platforms">Platforms</a>', $html);

        // The links follow the app's own InfoDot address when a host sets one.
        config(['ecosystem.platforms.infodot.url' => 'https://infodot.test/']);
        $this->actingAs($this->namedOwner())->get(route('dashboard'))
            ->assertSee('<a href="https://infodot.test/home">InfoDot</a>', false);
    }

    public function test_the_landing_sends_no_inline_event_handlers(): void
    {
        $html = $this->actingAs($this->namedOwner())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<header class="rg-land">(.*?)<\/header>/s', $html, $match));
        $this->assertSame(0, preg_match('/\son[a-z]+=|style=/', $match[1]));
    }

    public function test_the_platform_list_holds_only_current_platforms(): void
    {
        $current = [
            'agents', 'analytics', 'auction', 'central', 'charts', 'design', 'docs', 'dopemine', 'ehail', 'emall',
            'engage', 'files', 'finance', 'forms', 'hr', 'memory', 'mines', 'notify', 'press', 'projects', 'pulse',
            'sheet', 'tasks', 'tutor', 'infodot',
        ];

        $this->assertSame([], array_values(array_diff(array_keys(config('ecosystem.platforms')), $current)));
        $this->assertSame('Dot.HR', config('ecosystem.platforms.hr.name'));
    }
}
