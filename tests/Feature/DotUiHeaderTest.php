<?php

namespace Tests\Feature;

use App\Models\ProfessionalProfile;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * R-51: the header carries the shared Dot UI platform switcher, and the two in-page search boxes use the shared compact
 * filter. Dot.HR has no header search, so none was added. The switcher only ever shows what InfoDot says the CURRENT
 * company holds, links through InfoDot's /go (never straight to a host, never with a token), carries no employee data,
 * and is left off the public job board and public profiles.
 */
class DotUiHeaderTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ecosystem.infodot' => ['redeem_url' => self::REDEEM, 'platform' => 'hr', 'secret' => 'a-test-secret', 'timeout' => 2, 'provision' => true],
            'ecosystem.platforms.infodot.url' => 'https://infodot.test',
        ]);
    }

    private static function url(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    /** InfoDot's read endpoint, as it answers this platform about one company. */
    private static function body(string $uuid, array $held = ['hr', 'files', 'infodot']): array
    {
        $rows = [];
        foreach (['engage' => 'Dot.Engage', 'files' => 'Dot.Files', 'finance' => 'Dot.Finance', 'hr' => 'Dot.HR', 'infodot' => 'InfoDot', 'press' => 'Dot.Press'] as $key => $name) {
            $rows[] = ['key' => $key, 'name' => $name, 'status' => 'active', 'entitled' => in_array($key, $held, true), 'is_core' => false, 'access_mode' => 'everyone'];
        }

        return ['platform' => 'hr', 'company' => ['uuid' => $uuid, 'personal' => false], 'platforms' => $rows];
    }

    private function fakeInfoDot(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    /** An owner (HR admin) of a company InfoDot knows. */
    private function ownerOf(string $uuid, string $name): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $user->switchTeam($team);

        return $user->fresh();
    }

    /** The markup of the switcher only, so the assertions cannot be satisfied by the rest of the page. */
    private function switcherHtml(string $html): string
    {
        $this->assertSame(1, preg_match('/<div class="dsw dsw--header".*?<p class="dsw-sr" data-dsw-live[^>]*><\/p>\s*<\/div>\s*<\/div>\s*<\/div>/s', $html, $m), 'the header carries the switcher');

        return $m[0];
    }

    // ── No header search was added ──────────────────────────────────────────

    public function test_there_is_no_header_search_pill_and_the_workspace_pages_are_otherwise_unchanged(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->ownerOf(self::ACME, 'Acme Ltd');

        foreach (['dashboard', 'employees.index', 'hiring.index'] as $name) {
            $html = $this->actingAs($owner)->get(route($name))->assertOk()->getContent();
            $this->assertStringNotContainsString('class="dsr-input"', $html, $name.' has no header pill');
            $this->assertStringNotContainsString('dsr--nohint', $html);
        }
    }

    // ── The two in-page search boxes use the shared compact filter, logic untouched ────────────

    public function test_the_employee_search_is_the_compact_filter_with_the_same_form_parameter_and_placeholder(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->ownerOf(self::ACME, 'Acme Ltd');
        $this->employeeIn($owner->currentTeam, null, ['first_name' => 'Findable', 'last_name' => 'Person']);

        $html = $this->actingAs($owner)->get(route('employees.index', ['search' => 'Findab']))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<form method="GET" action="([^"]+)"/', $html, $form));
        $this->assertSame(route('employees.index'), html_entity_decode($form[1]));
        $this->assertMatchesRegularExpression('/<input[^>]*\bid="search"[^>]*\bname="search"[^>]*\bvalue="Findab"[^>]*>/s', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*\bclass="dsr-filter"[^>]*\bname="search"/s', $html);
        $this->assertStringContainsString('placeholder="Name, or an exact email address"', $html, 'the admin placeholder is kept');
        $this->assertStringContainsString('for="search"', $html, 'the visible label still points at the input');
        $this->assertStringContainsString('Findable', $html);
    }

    public function test_the_employee_search_never_crosses_businesses_and_keeps_its_email_rule(): void
    {
        $this->fakeInfoDot([
            self::url(self::ACME) => Http::response(self::body(self::ACME)),
            self::url(self::GLOBEX) => Http::response(self::body(self::GLOBEX)),
        ]);
        $acme = $this->ownerOf(self::ACME, 'Acme Ltd');
        $globex = $this->ownerOf(self::GLOBEX, 'Globex Ltd');
        $this->employeeIn($acme->currentTeam, null, ['first_name' => 'Zwelakhe', 'last_name' => 'Acmeonly', 'email' => 'acme.only@example.test']);
        $this->employeeIn($globex->currentTeam, null, ['first_name' => 'Zwelakhe', 'last_name' => 'Globexonly', 'email' => 'globex.only@example.test']);

        $this->actingAs($acme)->get(route('employees.index', ['search' => 'Zwelakhe']))->assertOk()->assertSee('Acmeonly')->assertDontSee('Globexonly');
        $this->actingAs($globex)->get(route('employees.index', ['search' => 'Zwelakhe']))->assertOk()->assertSee('Globexonly')->assertDontSee('Acmeonly');
        $this->actingAs($acme)->get(route('employees.index', ['search' => 'globex.only@example.test']))->assertOk()->assertDontSee('Globexonly');
        $this->actingAs($acme)->get(route('employees.index', ['search' => 'acme.only@example.test']))->assertOk()->assertSee('Acmeonly');
    }

    public function test_the_candidate_search_is_the_compact_filter_with_the_same_parameter(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->ownerOf(self::ACME, 'Acme Ltd');

        $html = $this->actingAs($owner)->get(route('hiring.candidates', ['q' => 'pastel']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*\bclass="dsr-filter"[^>]*\bname="q"[^>]*>/s', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*\bid="c-q"[^>]*\bvalue="pastel"[^>]*\bmaxlength="120"/s', $html);
        $this->assertStringContainsString('placeholder="Pastel, Mahikeng, bookkeeper"', $html);
        $this->assertSame(1, preg_match('/<form method="GET" action="([^"]+)"[^>]*role="search"/', $html, $form));
        $this->assertSame(route('hiring.candidates'), html_entity_decode($form[1]));
    }

    // ── The switcher ────────────────────────────────────────────────────────

    public function test_a_member_sees_the_switcher_with_what_their_company_holds_and_the_current_platform_marked(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->ownerOf(self::ACME, 'Acme Ltd');

        $html = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="ok"', $panel);
        $this->assertStringContainsString('data-platform="hr"', $panel);
        $this->assertStringContainsString('Acme Ltd', $panel, 'the sentence names the company');

        // Current platform: a marker, not a link.
        $this->assertSame(1, preg_match('/<div class="dsw-bay is-current" aria-current="page">.*?You are here/s', $panel, $here));
        $this->assertStringContainsString('Dot.HR', $here[0]);

        // Held platforms are links through InfoDot, with this company; InfoDot itself goes home.
        $this->assertStringContainsString('href="https://infodot.test/go/files?company='.self::ACME.'"', $panel);
        $this->assertStringContainsString('href="https://infodot.test/home"', $panel);
        // Not held: not a tile, only in the "not switched on" list that points at InfoDot's explanation.
        $this->assertStringNotContainsString('/go/press', $panel);
        $this->assertStringContainsString('href="https://infodot.test/platforms/press"', $panel);

        // Never a token, never a secret, never straight to a destination host.
        $this->assertStringNotContainsString('token', strtolower($panel));
        $this->assertStringNotContainsString('a-test-secret', $html);
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href, 'every way out of the switcher goes through InfoDot');
        }
    }

    public function test_the_switcher_asks_infodot_about_the_current_company_only_with_the_service_secret(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $this->actingAs($this->ownerOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->assertOk();

        Http::assertSent(function (OutgoingRequest $request) {
            return str_contains($request->url(), '/companies/'.self::ACME.'/platforms')
                && $request->hasHeader('Authorization', 'Bearer a-test-secret')
                && $request->hasHeader('X-Dot-Platform', 'hr');
        });
        Http::assertNotSent(fn (OutgoingRequest $request) => str_contains($request->url(), self::GLOBEX));
    }

    public function test_two_companies_never_see_each_others_switcher(): void
    {
        $this->fakeInfoDot([
            self::url(self::ACME) => Http::response(self::body(self::ACME, ['hr', 'infodot', 'press'])),
            self::url(self::GLOBEX) => Http::response(self::body(self::GLOBEX, ['hr', 'infodot', 'engage'])),
        ]);

        $acme = $this->switcherHtml($this->actingAs($this->ownerOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->getContent());
        $globex = $this->switcherHtml($this->actingAs($this->ownerOf(self::GLOBEX, 'Globex Ltd'))->get(route('dashboard'))->getContent());

        $this->assertStringContainsString('/go/press?company='.self::ACME, $acme);
        $this->assertStringNotContainsString('/go/engage', $acme);
        $this->assertStringNotContainsString(self::GLOBEX, $acme);
        $this->assertStringNotContainsString('Globex', $acme);
        $this->assertStringContainsString('/go/engage?company='.self::GLOBEX, $globex);
        $this->assertStringNotContainsString('/go/press', $globex);
        $this->assertStringNotContainsString(self::ACME, $globex);
        $this->assertStringNotContainsString('Acme', $globex);
    }

    public function test_the_switcher_carries_no_employee_data(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->ownerOf(self::ACME, 'Acme Ltd');
        $this->employeeIn($owner->currentTeam, null, ['first_name' => 'Quorvath', 'last_name' => 'Zelmarsh', 'email' => 'quorvath.zelmarsh@example.test', 'phone' => '+27 82 555 0199']);

        $response = $this->actingAs($owner)->get(route('employees.index'))->assertOk();
        $panel = $this->switcherHtml($response->getContent());

        foreach (['Quorvath', 'Zelmarsh', 'quorvath.zelmarsh', '555 0199', $owner->email, $owner->name] as $secret) {
            $this->assertStringNotContainsString($secret, $panel, 'the switcher markup never carries '.$secret);
        }
        // What InfoDot is sent is the company's uuid and nothing else.
        Http::assertSent(fn (OutgoingRequest $request) => ! str_contains($request->url(), 'Quorvath') && ! str_contains($request->body(), 'Quorvath') && ! str_contains($request->url(), (string) $owner->email));
    }

    public function test_when_infodot_cannot_be_reached_the_switcher_says_so_and_never_shows_active(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response('down', 503)]);

        $html = $this->actingAs($this->ownerOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="degraded"', $panel);
        $this->assertStringContainsString('Could not check your platforms just now', $panel);
        $this->assertStringContainsString('opened through InfoDot', $panel);
        $this->assertStringNotContainsString('Active', $panel);
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href);
        }
    }

    public function test_a_connection_failure_never_breaks_the_page(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => fn () => throw new ConnectionException('refused')]);

        $html = $this->actingAs($this->ownerOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-state="degraded"', $html);
    }

    public function test_a_personal_workspace_gets_the_honest_sentence_and_infodot_is_not_asked(): void
    {
        $this->fakeInfoDot([]);
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="personal"', $panel);
        $this->assertStringNotContainsString('dsw-bay', $panel, 'no platforms are listed for a workspace that is not a company');
        Http::assertNothingSent();
    }

    public function test_the_personal_career_space_asks_infodot_nothing_even_for_a_person_whose_current_team_is_a_company(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->ownerOf(self::ACME, 'Acme Ltd');
        // Warm the entitlement answer the ordinary way, then count only what the personal page asks.
        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
        $before = count(Http::recorded());

        $html = $this->actingAs($owner)->get(route('me.home'))->assertOk()->getContent();

        $this->assertStringContainsString('data-state="personal"', $this->switcherHtml($html));
        $this->assertStringNotContainsString('Acme Ltd', $this->switcherHtml($html));
        $this->assertSame($before, count(Http::recorded()), 'the personal career space asked InfoDot nothing');
    }

    // ── Public pages get neither the switcher nor a search ──────────────────

    public function test_the_public_job_pages_and_profiles_have_no_switcher_and_no_search_for_guests_and_members(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->ownerOf(self::ACME, 'Acme Ltd');
        $person = User::factory()->withPersonalTeam()->create(['name' => 'Kagiso Mahlangu']);
        $profile = ProfessionalProfile::forUser($person);
        $profile->update(['visibility' => 'public', 'headline' => 'Payroll clerk']);

        foreach ([route('jobs.index'), route('people.show', $profile->fresh()->handle)] as $url) {
            $guest = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('data-dsw', $guest);
            $this->assertStringNotContainsString('dsr-input', $guest);
            $this->assertStringNotContainsString('dsr-filter', $guest);

            $member = $this->actingAs($owner)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('data-dsw', $member, $url.' stays a public page for a signed-in visitor');
            $this->assertStringNotContainsString('dsr-input', $member);
            $this->assertStringNotContainsString('dsr-filter', $member);
            auth('web')->logout();
        }

        $this->get(route('login'))->assertOk()->assertDontSee('data-dsw', false);
    }

    public function test_the_job_board_keeps_its_own_search_form_untouched(): void
    {
        $html = $this->get(route('jobs.index', ['q' => 'clerk']))->assertOk()->getContent();

        $this->assertStringContainsString('id="job-search"', $html);
        $this->assertStringContainsString('action="'.route('jobs.index').'"', $html);
        $this->assertStringContainsString('class="dot-input"', $html, 'it still uses HR\'s own input, not the shared filter');
    }

    // ── The notification bell and its entitlement guard are untouched ───────

    public function test_the_bell_still_hides_for_a_refused_company_and_the_switcher_does_not_change_that(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->ownerOf(self::ACME, 'Acme Ltd');

        $html = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('wire:id', $html, 'the bell renders for a company that holds Dot.HR');
        $this->assertTrue(strpos($html, 'data-dsw') < strpos($html, 'wire:id'), 'the switcher sits before the bell');

        // Not entitled any more: the guarded route refuses, so neither page content nor a bell is rendered.
        Cache::flush();
        $body = self::body(self::ACME, ['infodot']);
        $this->fakeInfoDot([self::url(self::ACME) => Http::response($body)]);
        $this->actingAs($owner)->get(route('dashboard'))->assertStatus(403);
    }

    // ── CSP: self-hosted, nothing inline, nothing external ──────────────────

    public function test_the_shared_assets_are_served_from_this_site_and_resolve(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $response = $this->actingAs($this->ownerOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->assertOk();
        $html = $response->getContent();

        foreach (['css/dot-fonts.css', 'css/dot-tokens.css', 'css/dot-search.css', 'css/dot-switcher-fonts.css', 'css/dot-switcher.css', 'js/dot-switcher.js'] as $asset) {
            $this->assertStringContainsString(asset('dot-ui/'.$asset), $html, $asset.' is linked from this site');
            $this->assertFileExists(public_path('dot-ui/'.$asset));
        }

        // Every relative url() in the shared CSS lands on a file that is really there (fonts, arrow mask, stencil face).
        foreach (glob(public_path('dot-ui/css/*.css')) as $css) {
            preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/', (string) file_get_contents($css), $urls);
            foreach ($urls[1] as $url) {
                $this->assertDoesNotMatchRegularExpression('#^(https?:)?//#', $url, basename($css).' reaches outside this site');
                $this->assertFileExists(dirname($css).'/'.$url, basename($css).' -> '.$url);
            }
        }

        // Logos: this site's own copies, so strict img-src works.
        preg_match_all('/<img src="([^"]+)" alt="Dot\./', $this->switcherHtml($html), $imgs);
        $this->assertNotEmpty($imgs[1]);
        foreach ($imgs[1] as $src) {
            $this->assertStringStartsWith('/dot-ui/img/platforms/', $src);
            $this->assertFileExists(public_path($src));
        }

        // The policy gained no origin: only what it listed before (the font host and Unsplash).
        $policy = (string) $response->headers->get('Content-Security-Policy');
        preg_match_all('#https?://[^\s;]+#', $policy, $origins);
        $this->assertEqualsCanonicalizing(['https://fonts.googleapis.com', 'https://fonts.gstatic.com', 'https://images.unsplash.com'], array_values(array_unique($origins[0])));
    }

    public function test_the_shared_markup_has_no_inline_style_or_script(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->ownerOf(self::ACME, 'Acme Ltd');
        $html = $this->actingAs($owner)->get(route('employees.index'))->getContent();

        preg_match('/<input[^>]*class="dsr-filter"[^>]*>/', $html, $filter);
        foreach ([$filter[0] ?? '', $this->switcherHtml($html)] as $fragment) {
            $this->assertNotSame('', $fragment);
            $this->assertStringNotContainsString(' style=', $fragment);
            $this->assertStringNotContainsString('<style', $fragment);
            $this->assertStringNotContainsString('<script', $fragment);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $fragment, 'no inline event handlers');
        }
    }
}
