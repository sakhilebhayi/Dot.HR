<?php

namespace Tests\Feature;

use App\Livewire\NotificationBell;
use App\Models\Team;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * Guards the markup hooks of the responsive app shell: the shared dot-ui header with its phone drawer (R-52), the overflow
 * wrappers and the stacking helpers. These assert structure, not pixels: the drawer behaviour itself is dot-ui's dot-nav.js
 * and its CSS, so what can regress silently is a hook being renamed or removed.
 */
class ResponsiveShellTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    private function signedInPage(string $routeName): string
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam, null, ['first_name' => 'Shelltest', 'last_name' => 'Person']);
        $this->leaveFor($employee, now()->addDays(3)->toDateString(), now()->addDays(4)->toDateString());

        $response = $this->actingAs($owner)->get(route($routeName));
        $response->assertOk();

        return $response->getContent();
    }

    /** @return array<string, array{0: string}> */
    public static function shellPages(): array
    {
        return [
            'dashboard' => ['dashboard'],
            'employees' => ['employees.index'],
            'new employee form' => ['employees.create'],
            'leave requests' => ['leave-requests.index'],
            'new leave request form' => ['leave-requests.create'],
            'positions' => ['positions.index'],
        ];
    }

    #[DataProvider('shellPages')]
    public function test_every_app_page_carries_the_header_and_the_drawer(string $routeName): void
    {
        $html = $this->signedInPage($routeName);

        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"', $html);

        // One shared header, a labelled 44px menu button wired to the drawer it controls.
        $this->assertMatchesRegularExpression('/<header class="dh[ "]/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*class="[^"]*dh-menu-btn[^"]*"[^>]*data-dh-menu-button[^>]*aria-expanded="false"[^>]*aria-controls="dh-drawer"/', $html);
        $this->assertMatchesRegularExpression('/<div class="dh-drawer" id="dh-drawer" data-dh-drawer/', $html);
        $this->assertStringContainsString('dot-ui/js/dot-ui-core.min.js', $html);

        // The old hand-written shell is gone: no second drawer, no inline shell script or style.
        $this->assertStringNotContainsString('data-shell-menu', $html);
        $this->assertStringNotContainsString('id="app-sidebar"', $html);
        $this->assertDoesNotMatchRegularExpression('/<header class="topbar"/', $html);
    }

    public function test_the_menu_button_is_inside_the_one_header_and_there_is_one_search(): void
    {
        $html = $this->signedInPage('dashboard');

        $this->assertSame(1, substr_count($html, '<header class="dh'));
        $this->assertSame(1, substr_count($html, 'data-dh-menu-button'));
        $this->assertSame(1, substr_count($html, 'data-dh-search>'));
        $this->assertSame(1, substr_count($html, 'data-dsw-trigger'), 'one platform switcher, not two');
    }

    public function test_data_tables_sit_inside_a_horizontal_scroll_wrapper(): void
    {
        foreach (['employees.index', 'leave-requests.index', 'positions.index', 'leave-types.index'] as $routeName) {
            $html = $this->signedInPage($routeName);

            $this->assertMatchesRegularExpression(
                '/<div class="[^"]*\btable-scroll\b[^"]*"[^>]*>\s*<table/',
                $html,
                "The main table on [$routeName] must be wrapped in .table-scroll so it never widens the page."
            );
        }
    }

    public function test_forms_and_split_layouts_use_the_stacking_hook(): void
    {
        foreach (['employees.create', 'leave-requests.create', 'employees.index', 'dashboard'] as $routeName) {
            $this->assertStringContainsString('r-stack', $this->signedInPage($routeName), "[$routeName] needs .r-stack so it collapses to one column on phones.");
        }
    }

    public function test_the_stacking_hook_has_its_phone_rule(): void
    {
        // The views set their columns inline, so the hook only works while this rule exists
        // and outranks them. It was lost once, leaving forms two columns wide on a phone.
        $css = preg_replace('/\s+/', ' ', (string) file_get_contents(resource_path('css/register.css')));

        $this->assertStringContainsString(
            '@media (max-width: 720px) { .r-stack { grid-template-columns: minmax(0, 1fr) !important; }',
            $css
        );
    }

    public function test_page_wrappers_use_the_responsive_padding_hook(): void
    {
        foreach (['dashboard', 'employees.index', 'employees.create'] as $routeName) {
            $this->assertStringContainsString('class="page-pad"', $this->signedInPage($routeName));
        }
    }

    public function test_the_notification_bell_stays_in_the_top_bar_and_is_a_panel(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSeeLivewire('notification-bell')->getContent();
        $this->assertStringContainsString('data-dh-bell', $html);
        $this->assertStringContainsString('id="notifications-panel"', $html);

        Livewire::actingAs($user)->test(NotificationBell::class)
            ->assertSet('loaded', false)
            ->call('load')
            ->assertSet('loaded', true)
            ->assertSeeHtml('class="dh-panel__list"');
    }

    private function accountXpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    private function dashboardHtmlFor(User $user): string
    {
        return $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk()->getContent();
    }

    /** The shared account menu (desktop dropdown) and its twin in the phone drawer. */
    private const MENU = '//div[contains(@class,"dh-account")]//div[@data-dh-menu-panel]';

    private const DRAWER_ACCOUNT = '//div[@data-dh-drawer]//section[contains(@class,"dh-dacct")]';

    public function test_the_account_menu_carries_team_settings_create_team_and_sign_out_on_desktop_and_in_the_drawer(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $xpath = $this->accountXpath($this->dashboardHtmlFor($user));

        foreach ([self::MENU, self::DRAWER_ACCOUNT] as $account) {
            $this->assertSame(1, $xpath->query($account)->length, 'The account section exists in '.$account);
            $this->assertSame(1, $xpath->query($account.'//a[@href="'.route('teams.show', $team->id).'"][contains(.,"Team settings")]')->length);
            $this->assertSame(1, $xpath->query($account.'//a[@href="'.route('teams.create').'"][contains(.,"Create a team")]')->length);
            $this->assertSame(1, $xpath->query($account.'//a[@href="'.route('profile.show').'"]')->length);

            $logout = $xpath->query($account.'//form[@method="post"][@action="'.route('logout').'"]');
            $this->assertSame(1, $logout->length, 'Sign out is a POST form');
            $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $logout->item(0))->length);
            $this->assertSame(1, $xpath->query('.//button[@type="submit"]', $logout->item(0))->length);
        }
        $this->assertSame(0, $xpath->query('//a[@href="'.route('logout').'"]')->length, 'There is no GET logout link');
        $this->assertSame(1, $xpath->query(self::MENU.'//button[@data-dh-theme-set="dark"]')->length, 'the theme choice lives in the account menu');
    }

    public function test_create_team_is_left_out_when_the_user_may_not_create_one(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        Gate::before(fn ($authUser, $ability, $arguments) => $ability === 'create' && ($arguments[0] ?? null) instanceof Team ? false : null);

        $xpath = $this->accountXpath($this->dashboardHtmlFor($user));

        $this->assertSame(1, $xpath->query(self::MENU.'//a[@href="'.route('teams.show', $user->currentTeam->id).'"]')->length);
        $this->assertSame(0, $xpath->query('//a[@href="'.route('teams.create').'"]')->length);
    }

    public function test_a_user_in_several_teams_gets_one_switch_form_per_other_team_with_the_current_one_marked(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $current = $user->currentTeam;
        $other = Team::factory()->create(['user_id' => $user->id, 'name' => 'Second Account Team', 'personal_team' => false]);

        $xpath = $this->accountXpath($this->dashboardHtmlFor($user));
        $forms = $xpath->query(self::MENU.'//form[@action="'.route('current-team.update').'"]');

        $this->assertSame(1, $forms->length, 'One switch form for the one other business; the current one is a link home');
        $this->assertSame(1, $xpath->query(self::MENU.'//form[@action="'.route('current-team.update').'"][input[@name="_method"][@value="PUT"]][input[@name="_token"]][input[@name="team_id"][@value="'.$other->id.'"]]//button[contains(.,"Second Account Team")]')->length);
        $this->assertSame(1, $xpath->query(self::MENU.'//a[@aria-current="page"][contains(.,"'.$current->name.'")]')->length, 'The current team is marked');
        $this->assertSame(1, $xpath->query(self::MENU.'//*[normalize-space()="Workspace"]')->length);
        $this->assertSame(1, $xpath->query(self::MENU.'//a[@href="'.route('me.home').'"][contains(.,"Personal")]')->length, 'the personal career space is one row of the same menu');
    }

    public function test_a_user_in_one_team_gets_no_switch_forms(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $xpath = $this->accountXpath($this->dashboardHtmlFor($user));

        $this->assertSame(1, $xpath->query(self::MENU)->length);
        $this->assertSame(0, $xpath->query('//form[@action="'.route('current-team.update').'"]')->length);
    }

    public function test_the_legacy_jetstream_bar_is_not_rendered_and_content_starts_under_the_header(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->dashboardHtmlFor($user);

        $this->assertDoesNotMatchRegularExpression('/<nav\b[^>]*\bwire:id=/', $html, 'The stock Jetstream bar (a Livewire nav) must not render at any width');
        $this->assertStringNotContainsString('Manage Account', $html, 'Text only the legacy bar rendered');
        // The dot-ui header is sticky, so the content wrapper starts right under it: no fixed-bar offset, no inline shell style.
        $this->assertDoesNotMatchRegularExpression('/--topbar-h/', $html);
        preg_match_all('/<style\b[^>]*>(.*?)<\/style>/s', $html, $blocks);
        $this->assertStringNotContainsString('.topbar', implode("\n", $blocks[1]), 'the shell has no inline style block of its own any more (Livewire\'s own is the only one)');
        $this->assertMatchesRegularExpression('/\.content-wrap\s*\{\s*padding-top:\s*0/', (string) file_get_contents(resource_path('css/register.css')));
    }

    public function test_the_account_menu_is_never_hidden_by_a_css_rule_at_any_width(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $html = $this->dashboardHtmlFor($user);

        $xpath = $this->accountXpath($html);
        $this->assertSame(1, $xpath->query(self::MENU)->length);
        $this->assertSame(1, $xpath->query(self::DRAWER_ACCOUNT)->length, 'the phone drawer carries the account too');

        $css = preg_replace('/\s+/', ' ', (string) file_get_contents(resource_path('css/register.css')));
        $this->assertDoesNotMatchRegularExpression('/\.dh-(account|dacct|signout)[^{]*\{[^}]*display\s*:\s*none/', $css, 'The host does not hide the shared account menu');
    }
}
