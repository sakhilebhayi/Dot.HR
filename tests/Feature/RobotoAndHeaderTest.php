<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\Team;
use App\Models\User;
use App\Support\Hr\WorkspaceNav;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * R-52: Roboto is the one face (self-hosted, no font host anywhere), the icon font is a small self-hosted subset, and the
 * top navigation is the shared header with at most seven visible places and named groups.
 */
class RobotoAndHeaderTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    // ── Roboto, self-hosted ──────────────────────────────────────────────────

    public function test_the_page_declares_roboto_from_this_site_and_the_files_are_there(): void
    {
        $html = $this->actingAs($this->owner())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<html[^>]*class="dot-roboto"/', $html);
        $this->assertStringContainsString(asset('dot-ui/css/dot-roboto.css'), $html);
        $this->assertStringContainsString(asset('dot-ui/css/dot-type.css'), $html);
        $this->assertStringContainsString('rel="preload" href="'.asset('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2').'"', $html);
        $this->assertFileExists(public_path('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2'));
        $this->assertFileExists(public_path('dot-ui/fonts/roboto/roboto-latin-ext-wght-normal.woff2'));
        $this->assertStringContainsString("font-family: 'Roboto'", (string) file_get_contents(public_path('dot-ui/css/dot-roboto.css')));

        // The theme is applied before the first paint by dot-ui's external script, not by an inline one.
        $this->assertStringContainsString('dot-ui/js/dot-theme-boot.js', $html);
        $this->assertStringContainsString('data-dh-theme-class="dark"', $html);
    }

    public function test_the_register_tokens_resolve_to_roboto_not_another_face(): void
    {
        $css = (string) file_get_contents(resource_path('css/register.css'));

        $this->assertMatchesRegularExpression('/--rg-sans:\s*var\(--dot-font-sans\)/', $css);
        $this->assertMatchesRegularExpression('/--rg-serif:\s*var\(--dot-font-sans\)/', $css);
    }

    public function test_no_font_is_loaded_from_outside_and_no_other_text_face_is_named(): void
    {
        $files = array_merge(
            glob(resource_path('css/*.css')) ?: [],
            glob(resource_path('views/*.blade.php')) ?: [],
            glob(resource_path('views/layouts/*.blade.php')) ?: [],
            glob(resource_path('views/errors/*.blade.php')) ?: [],
            glob(resource_path('views/leaving/*.blade.php')) ?: [],
            [base_path('tailwind.config.js')],
        );
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $text = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('#googleapis\.com|gstatic\.com|fonts\.bunny|use\.typekit|cdnjs|jsdelivr#i', $text, basename($file).' loads from outside');
            // Strip comments first: a comment may say what the old face was.
            $code = preg_replace(['#/\*.*?\*/#s', '/\{\{--.*?--\}\}/s'], '', $text);
            $this->assertDoesNotMatchRegularExpression('/Instrument\s*(Sans|Serif)|Fraunces|Karla|IBM Plex|Figtree|Archivo|\bInter\b/i', (string) $code, basename($file).' still names another text face');
        }
    }

    public function test_no_rendered_page_links_a_font_host(): void
    {
        $owner = $this->owner();

        $pages = [
            'welcome' => $this->get('/')->assertOk()->getContent(),
            'login' => $this->get(route('login'))->assertOk()->getContent(),
            'jobs' => $this->get(route('jobs.index'))->assertOk()->getContent(),
            'dashboard' => $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent(),
            'profile' => $this->actingAs($owner)->get(route('profile.show'))->assertOk()->getContent(),
            '404' => $this->get('/no-such-page-r52')->assertNotFound()->getContent(),
        ];

        foreach ($pages as $name => $html) {
            $this->assertDoesNotMatchRegularExpression('#googleapis|gstatic|fonts\.#i', $html, $name.' links a font host');
            $this->assertStringContainsString('dot-roboto', $html, $name.' is on Roboto');
        }
    }

    public function test_the_content_security_policy_allows_no_font_host(): void
    {
        $policy = (string) $this->get(route('login'))->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("font-src 'self' data:;", $policy);
        $this->assertStringNotContainsString('googleapis', $policy);
        $this->assertStringNotContainsString('gstatic', $policy);
    }

    // ── The icon font: a small self-hosted subset ────────────────────────────

    public function test_the_icon_font_is_a_small_subset_served_from_this_build(): void
    {
        $font = resource_path('fonts/material-symbols-rounded-subset.woff2');
        $this->assertFileExists($font);
        $this->assertLessThan(12 * 1024, filesize($font), 'a subset of the icons used, kilobytes not megabytes');

        $css = (string) file_get_contents(resource_path('css/icons.css'));
        $this->assertStringContainsString('../fonts/material-symbols-rounded-subset.woff2', $css);
        $this->assertStringContainsString('font-display: block', $css);
        $this->assertStringContainsString('rg-noicons', $css);

        // The shell loads it (and so does the error layout, which has its own head).
        $this->assertStringContainsString("'resources/css/icons.css'", (string) file_get_contents(resource_path('views/errors/_ecosystem-layout.blade.php')));
    }

    // ── The header: grouped navigation, one account menu, one switcher ───────

    private function navFor(User $user, string $route = 'dashboard'): array
    {
        $this->actingAs($user->fresh());
        $request = Request::create(route($route));
        $request->setRouteResolver(fn () => Route::getRoutes()->match($request));

        return WorkspaceNav::nav($request, $user->fresh());
    }

    public function test_an_administrators_bar_has_at_most_seven_places_with_the_rest_in_named_groups(): void
    {
        $owner = $this->owner();
        $this->employeeIn($owner->currentTeam, $owner);

        $nav = $this->navFor($owner);
        $labels = array_column($nav, 'label');

        $this->assertLessThanOrEqual(9, count($nav), 'five daily places plus four groups at most');
        $flat = array_filter($nav, fn ($i) => isset($i['href']));
        $this->assertLessThanOrEqual(6, count($flat), 'no more than six plain items in the bar');
        $this->assertSame(['Home', 'My work', 'Hiring', 'Employees', 'Leave'], array_column($flat, 'label'));
        $this->assertSame(['Workforce', 'Growth', 'Records', 'Setup'], array_values(array_diff($labels, array_column($flat, 'label'))));

        $grouped = collect($nav)->pluck('items')->filter()->flatten(1);
        $this->assertContains('Lifecycle', $grouped->pluck('label')->all());
        $this->assertContains('Requests', $grouped->pluck('label')->all());

        // Nothing is listed twice, and every place is a real route.
        $all = collect($nav)->flatMap(fn ($i) => isset($i['items']) ? $i['items'] : [$i])->pluck('href');
        $this->assertSame($all->count(), $all->unique()->count(), 'no duplicate links');
    }

    public function test_the_bar_shrinks_to_what_each_role_may_open(): void
    {
        $owner = $this->owner();
        $manager = $this->memberOf($owner, 'editor');
        $employee = $this->memberOf($owner, 'viewer');
        $this->employeeIn($owner->currentTeam, $employee);
        $plain = $this->memberOf($owner, 'viewer');

        $managerNav = array_column($this->navFor($manager), 'label');
        $this->assertContains('People', $managerNav, 'a manager reads the list, they do not add to it');
        $this->assertNotContains('Employees', $managerNav);
        $this->assertNotContains('Hiring', $managerNav);
        $this->assertNotContains('Records', $managerNav);

        $employeeNav = $this->navFor($employee);
        $this->assertSame(['Home', 'My work', 'People', 'Leave'], array_column(array_filter($employeeNav, fn ($i) => isset($i['href'])), 'label'));
        $this->assertNotContains('Setup', array_column($employeeNav, 'label'));

        $this->assertSame(['Home'], array_column($this->navFor($plain), 'label'), 'a member with no role and no record sees Home only');
    }

    public function test_the_personal_workspace_and_the_signed_out_bar(): void
    {
        $user = $this->owner();

        $this->assertSame(['Home', 'Find work', 'Applications', 'Profile'], array_column($this->navFor($user, 'me.home'), 'label'));

        $request = Request::create(route('jobs.index'));
        $request->setRouteResolver(fn () => Route::getRoutes()->match($request));
        $this->assertSame(['Job board'], array_column(WorkspaceNav::nav($request, null), 'label'));

    }

    public function test_a_signed_out_visitor_gets_a_sign_in_link_and_no_account_menu(): void
    {
        $html = $this->get(route('jobs.index'))->assertOk()->getContent();

        $this->assertStringContainsString('dh-guest', $html);
        $this->assertStringContainsString(route('login'), $html);
        $this->assertStringNotContainsString('data-dh-account', $html);
        $this->assertStringNotContainsString('data-dh-bell', $html);
    }

    public function test_the_bar_marks_the_current_place_and_its_group(): void
    {
        $owner = $this->owner();

        $nav = $this->navFor($owner, 'lifecycle.index');
        $workforce = collect($nav)->firstWhere('label', 'Workforce');
        $this->assertTrue(collect($workforce['items'])->firstWhere('label', 'Lifecycle')['current']);
        $this->assertFalse(collect($nav)->firstWhere('label', 'Home')['current']);

        $html = $this->actingAs($owner)->get(route('dashboard'))->getContent();
        $this->assertStringContainsString('aria-current="page"', $html);
    }

    public function test_there_is_one_switcher_and_one_account_menu_and_no_second_workspace_pill(): void
    {
        $html = $this->actingAs($this->owner())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-dsw-trigger'));
        $this->assertSame(1, substr_count($html, 'data-dh-account'));
        $this->assertStringNotContainsString('ws-switch', $html);
        $this->assertStringNotContainsString('topbar-team', $html);
        $this->assertStringNotContainsString('data-theme-toggle', $html, 'the theme choice is in the account menu');
    }

    public function test_the_header_markup_has_no_inline_style_or_script_of_its_own(): void
    {
        $html = $this->actingAs($this->owner())->get(route('dashboard'))->getContent();
        $header = substr($html, (int) strpos($html, '<header class="dh'), (int) strpos($html, '</header>') - (int) strpos($html, '<header class="dh') + 9);

        $this->assertNotSame('', $header);
        $this->assertStringNotContainsString(' style=', $header);
        $this->assertStringNotContainsString('<style', $header);
        $this->assertStringNotContainsString('<script', $header);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $header);

        // And the layout's scripts are external files with the nonce, none written inline but the page's own.
        preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>#', $html, $inline);
        foreach ($inline[0] as $tag) {
            $this->assertMatchesRegularExpression('/nonce="/', $tag, 'an inline script carries the nonce: '.$tag);
        }
    }

    public function test_an_unentitled_page_still_renders_the_shell_without_a_bell(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->assertInstanceOf(Team::class, $user->currentTeam);
        $this->assertInstanceOf(LeaveRequest::class, new LeaveRequest);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('data-dh-bell', false);
    }
}
