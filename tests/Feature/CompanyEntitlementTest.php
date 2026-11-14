<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureCompanyEntitled;
use App\Livewire\NotificationBell;
use App\Models\Employee;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R-50: Dot.HR honours the company's entitlement. At sign-in only companies InfoDot marks `entitled: true` are
 * admitted; during a session the current company is re-checked against InfoDot's read endpoint. The bodies are
 * the ones InfoDot's own tests pin (PlatformDoorsTest, CompanyPlatformsEndpointTest).
 */
class CompanyEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const READ = 'https://infodot.test/api/ecosystem/companies/*';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    private string $bearer;

    /** Calls that reached InfoDot's read endpoint, counted here because a call that throws is not recorded. */
    private int $asked = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bearer = Str::random(40);

        config(['ecosystem.infodot' => [
            'redeem_url' => self::REDEEM, 'platform' => 'hr', 'secret' => $this->bearer, 'timeout' => 2, 'provision' => true,
        ]]);
        config(['ecosystem.platforms.infodot.url' => 'https://infodot.test']);
    }

    // ---------------------------------------------------------------- sign-in (redeem)

    private function redeemed(array $teams, ?string $current = self::ACME): TestResponse
    {
        Http::swap(new Factory);
        Http::fake([self::REDEEM => Http::response([
            'platform' => 'hr',
            'user' => ['id' => 7, 'uuid' => (string) Str::uuid(), 'name' => 'Amara Dlamini', 'email' => 'amara@example.test', 'email_verified' => true],
            'teams' => $teams,
            'current_team_id' => 3,
            'current_team_uuid' => $current,
        ])]);

        auth('web')->logout();

        return $this->post(route('ecosystem.auth'), ['token' => 'one-time'], ['Origin' => 'https://infodot.test']);
    }

    private function listed(string $uuid, string $name, array $extra = [], string $role = 'owner'): array
    {
        return ['id' => random_int(1, 999), 'uuid' => $uuid, 'name' => $name, 'personal' => false, 'role' => $role, 'role_label' => ucfirst($role)] + $extra;
    }

    private function personal(array $extra = []): array
    {
        return ['id' => 1, 'uuid' => (string) Str::uuid(), 'name' => "Amara's Team", 'personal' => true, 'role' => 'owner'] + $extra;
    }

    public function test_a_company_marked_entitled_is_admitted_at_sign_in(): void
    {
        $this->redeemed([
            $this->personal(['entitled' => false, 'access' => 'no_personal_access']),
            $this->listed(self::ACME, 'Acme Ltd', ['entitled' => true, 'access' => null]),
        ])->assertRedirect(route('dashboard'));

        $user = User::where('email', 'amara@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(self::ACME, $user->fresh()->currentTeam->infodot_team_uuid);
    }

    public function test_a_company_marked_not_entitled_is_not_created_and_cannot_become_current(): void
    {
        $this->redeemed([
            $this->listed(self::ACME, 'Acme Ltd', ['entitled' => true, 'access' => null]),
            $this->listed(self::GLOBEX, 'Globex', ['entitled' => false, 'access' => 'not_activated']),
        ], self::GLOBEX)->assertRedirect(route('dashboard'));

        $this->assertNull(Team::where('infodot_team_uuid', self::GLOBEX)->first(), 'a company without the platform is not created here');

        $user = User::where('email', 'amara@example.test')->firstOrFail();
        $this->assertNotSame(self::GLOBEX, $user->currentTeam->infodot_team_uuid);
        $this->assertNotNull(Team::where('infodot_team_uuid', self::ACME)->first());
    }

    public function test_a_company_that_lost_the_platform_is_left_untouched_and_is_no_longer_current(): void
    {
        // First sign-in, before R-50 reached InfoDot: both companies arrive and Globex is the active one.
        $this->redeemed([$this->listed(self::ACME, 'Acme Ltd'), $this->listed(self::GLOBEX, 'Globex')], self::GLOBEX);

        $user = User::where('email', 'amara@example.test')->firstOrFail();
        $globex = Team::where('infodot_team_uuid', self::GLOBEX)->firstOrFail();
        $this->assertSame($globex->id, $user->fresh()->current_team_id);
        $employee = Employee::factory()->create(['team_id' => $globex->id]);

        // Globex's access ends, and InfoDot renames it meanwhile.
        $this->redeemed([
            $this->listed(self::ACME, 'Acme Ltd', ['entitled' => true, 'access' => null]),
            $this->listed(self::GLOBEX, 'Globex Renamed', ['entitled' => false, 'access' => 'suspended']),
        ], self::GLOBEX)->assertRedirect(route('dashboard'));

        $user = $user->fresh();
        $this->assertSame(self::ACME, $user->currentTeam->infodot_team_uuid, 'moved to the company that holds the platform');
        $this->assertSame('Globex', $globex->fresh()->name, 'not updated');
        $this->assertTrue($user->belongsToTeam($globex->fresh()), 'membership is not removed when access ends');
        $this->assertNotNull(Employee::withoutGlobalScopes()->find($employee->id), 'records are kept');
    }

    public function test_an_answer_without_the_entitled_key_behaves_exactly_as_before(): void
    {
        $this->redeemed([$this->listed(self::ACME, 'Acme Ltd'), $this->listed(self::GLOBEX, 'Globex')], self::GLOBEX)
            ->assertRedirect(route('dashboard'));

        $user = User::where('email', 'amara@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull(Team::where('infodot_team_uuid', self::ACME)->first());
        $this->assertSame(self::GLOBEX, $user->fresh()->currentTeam->infodot_team_uuid);
    }

    public function test_only_exactly_true_admits_a_company(): void
    {
        $this->redeemed([
            $this->listed(self::ACME, 'Acme Ltd', ['entitled' => true, 'access' => null]),
            $this->listed(self::GLOBEX, 'Globex', ['entitled' => 1, 'access' => null]),
        ]);

        $this->assertNull(Team::where('infodot_team_uuid', self::GLOBEX)->first());
    }

    public function test_when_no_company_is_entitled_the_person_gets_the_plain_page_and_nothing_is_created(): void
    {
        $response = $this->redeemed([
            $this->personal(['entitled' => false, 'access' => 'no_personal_access']),
            $this->listed(self::ACME, 'Acme Ltd', ['entitled' => false, 'access' => 'not_activated']),
        ]);

        $response->assertStatus(403)
            ->assertSee('Dot.HR is not switched on for your company')
            ->assertSee('href="https://infodot.test/platforms/hr"', false)
            ->assertSee('Open Platforms in InfoDot')
            ->assertDontSee('Sign out')
            ->assertDontSee('Stack trace')
            ->assertDontSee('Acme Ltd');

        $this->assertGuest();
        $this->assertSame(0, User::count(), 'no account is created');
        $this->assertSame(0, Team::count(), 'no company is created');
        $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action'), 'one action');
    }

    public function test_the_sign_in_page_is_worded_from_the_reason(): void
    {
        $this->redeemed([$this->listed(self::ACME, 'Acme Ltd', ['entitled' => false, 'access' => 'payment_required'])])
            ->assertStatus(403)->assertSee('Dot.HR needs a payment from your company');

        $this->redeemed([$this->listed(self::ACME, 'Acme Ltd', ['entitled' => false, 'access' => 'not_permitted_for_you'], 'viewer')])
            ->assertStatus(403)->assertSee('Your company has not given you Dot.HR');
    }

    // ---------------------------------------------------------------- during a session

    /** The body InfoDot's CompanyPlatformsEndpointTest pins, with Dot.HR's own row in the given state. */
    private function platforms(bool $entitled, string $status, string $uuid = self::ACME): array
    {
        return [
            'platform' => 'hr',
            'company' => ['uuid' => $uuid, 'personal' => false],
            'platforms' => [
                ['key' => 'finance', 'name' => 'Dot.Finance', 'status' => 'included', 'entitled' => true, 'is_core' => true],
                ['key' => 'forms', 'name' => 'Dot.Forms', 'status' => 'active', 'entitled' => true, 'is_core' => false],
                ['key' => 'hr', 'name' => 'Dot.HR', 'status' => $status, 'entitled' => $entitled, 'is_core' => false, 'access_mode' => 'everyone'],
                ['key' => 'infodot', 'name' => 'InfoDot', 'status' => 'included', 'entitled' => true, 'is_core' => true],
            ],
        ];
    }

    private function infodotAnswers(mixed $response): void
    {
        Http::swap(new Factory);
        Http::fake([self::READ => function ($request) use ($response) {
            $this->asked++;

            return $response instanceof \Closure ? $response($request) : $response;
        }]);
    }

    /** Someone at work in a company InfoDot knows, with an employee on file. */
    private function atWork(string $uuid = self::ACME, string $name = 'Acme Ltd'): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = Team::factory()->create(['user_id' => $user->id, 'personal_team' => false, 'name' => $name]);
        $team->forceFill(['infodot_team_uuid' => $uuid])->save();
        $user->switchTeam($team);

        $employee = Employee::factory()->create(['team_id' => $team->id, 'first_name' => 'Zwelibanzi', 'last_name' => 'Okonkwo-Record']);

        return [$user->fresh(), $team, $employee];
    }

    public function test_a_company_that_holds_the_platform_carries_on_and_infodot_is_asked_properly(): void
    {
        [$user] = $this->atWork();
        $this->infodotAnswers(Http::response($this->platforms(true, 'active')));

        $this->actingAs($user)->get(route('employees.index'))->assertOk()->assertSee('Okonkwo-Record');

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://infodot.test/api/ecosystem/companies/'.self::ACME.'/platforms?')
            && str_contains($request->url(), 'platform=hr')
            && $request->hasHeader('X-Dot-Platform', 'hr')
            && $request->hasHeader('Authorization', 'Bearer '.$this->bearer));
    }

    public static function refusals(): array
    {
        return [
            'never switched on' => ['free_while_pricing_finalised', 'Dot.HR is not switched on for your company'],
            'suspended' => ['suspended', 'Dot.HR is suspended for your company'],
            'payment needed' => ['payment_required', 'Dot.HR needs a payment from your company'],
            'ended' => ['subscription_expired', 'Dot.HR has ended for your company'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_company_that_does_not_hold_the_platform_gets_a_403_with_no_data(string $status, string $heading): void
    {
        [$user, $team, $employee] = $this->atWork();
        $this->infodotAnswers(Http::response($this->platforms(false, $status)));

        foreach ([route('dashboard'), route('employees.index'), route('employees.show', $employee), route('my-work.index')] as $url) {
            $response = $this->actingAs($user)->get($url)->assertStatus(403);

            $response->assertSee($heading)
                ->assertSee('href="https://infodot.test/platforms/hr"', false)
                ->assertDontSee('Zwelibanzi')
                ->assertDontSee('Okonkwo-Record')
                ->assertDontSee($employee->email)
                ->assertDontSee('Acme Ltd')
                ->assertDontSee('app-sidebar')
                ->assertDontSee('the rest of the Dot Ecosystem');
            $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action'), 'one action');
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }
    }

    public function test_nothing_is_changed_or_deleted_while_access_is_off(): void
    {
        [$user, $team, $employee] = $this->atWork();
        $this->infodotAnswers(Http::response($this->platforms(false, 'suspended')));
        $before = $employee->fresh()->getAttributes();

        $this->actingAs($user)->put(route('employees.update', $employee), ['first_name' => 'Changed', 'last_name' => 'Changed'])->assertStatus(403);
        $this->actingAs($user)->delete(route('employees.destroy', $employee))->assertStatus(403);
        $this->actingAs($user)->post(route('employees.store'), ['first_name' => 'New', 'last_name' => 'Person'])->assertStatus(403);
        $this->actingAs($user)->getJson(route('employees.index'))->assertStatus(403)->assertJsonMissingPath('data')->assertJsonPath('infodot_url', 'https://infodot.test/platforms/hr');

        $this->assertSame($before, $employee->fresh()->getAttributes());
        $this->assertSame(1, Employee::withoutGlobalScopes()->withTrashed()->where('team_id', $team->id)->count());
        $this->assertTrue($user->fresh()->belongsToTeam($team));
    }

    public static function failures(): array
    {
        return [
            'timeout or connection error' => [fn () => fn () => throw new ConnectionException('timed out')],
            '401 wrong secret' => [fn () => Http::response(['error' => 'invalid_request'], 401)],
            '403 platform switched off' => [fn () => Http::response(['error' => 'invalid_request'], 403)],
            '404 unknown company' => [fn () => Http::response(['error' => 'not_found'], 404)],
            '500' => [fn () => Http::response('Server Error', 500)],
            '503' => [fn () => Http::response('', 503)],
            'not json' => [fn () => Http::response('<html>hello</html>', 200)],
            'no platforms list' => [fn () => Http::response(['platform' => 'hr', 'company' => ['uuid' => self::ACME]], 200)],
            'own row missing' => [fn () => Http::response(['platform' => 'hr', 'company' => ['uuid' => self::ACME, 'personal' => false], 'platforms' => [
                ['key' => 'forms', 'name' => 'Dot.Forms', 'status' => 'suspended', 'entitled' => false, 'is_core' => false],
            ]], 200)],
            'entitled is not a boolean' => [fn () => Http::response(['platforms' => [['key' => 'hr', 'status' => 'suspended', 'entitled' => 'no']]], 200)],
        ];
    }

    #[DataProvider('failures')]
    public function test_any_failure_at_infodot_carries_on_and_is_logged_once_per_window(\Closure $answer): void
    {
        [$user] = $this->atWork();
        $this->infodotAnswers($answer());

        Log::spy();

        $this->actingAs($user)->get(route('employees.index'))->assertOk()->assertSee('Okonkwo-Record');
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $this->assertSame(1, $this->asked);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'entitlement check could not be completed'))->once();

        // The failure is remembered for a minute only, then InfoDot is asked again.
        $this->travel(61)->seconds();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->assertSame(2, $this->asked);
    }

    public function test_a_missing_secret_carries_on_without_calling_infodot(): void
    {
        [$user] = $this->atWork();
        config(['ecosystem.infodot.secret' => null]);
        $this->infodotAnswers(Http::response($this->platforms(false, 'suspended')));
        Log::spy();

        $this->actingAs($user)->get(route('employees.index'))->assertOk();
        $this->actingAs($user)->get(route('employees.index'))->assertOk();

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_the_answer_is_remembered_per_company_for_ten_minutes(): void
    {
        [$user] = $this->atWork();
        $this->infodotAnswers(Http::response($this->platforms(true, 'active')));

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->actingAs($user)->get(route('employees.index'))->assertOk();
        Http::assertSentCount(1);

        $this->travel(9)->minutes();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        Http::assertSentCount(1);

        $this->travel(2)->minutes();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        Http::assertSentCount(2);
    }

    public function test_the_flag_off_makes_no_call(): void
    {
        [$user] = $this->atWork();
        config(['hr.entitlement_check' => false]);
        $this->infodotAnswers(Http::response($this->platforms(false, 'suspended')));

        $this->actingAs($user)->get(route('employees.index'))->assertOk()->assertSee('Okonkwo-Record');

        Http::assertNothingSent();
    }

    public function test_the_flag_is_on_by_default(): void
    {
        $this->assertTrue((require config_path('hr.php'))['entitlement_check']);
        $this->assertStringContainsString('HR_ENTITLEMENT_CHECK=true', file_get_contents(base_path('.env.example')));
    }

    public function test_exempt_routes_make_no_call(): void
    {
        [$user] = $this->atWork();
        $this->infodotAnswers(Http::response($this->platforms(false, 'suspended')));

        // Public pages and checks, also for someone who is signed in.
        foreach (['/', '/jobs', '/cookies', '/up', '/ready', '/login', '/terms-of-service', '/privacy-policy'] as $path) {
            $this->get($path)->assertSuccessful();
            $this->actingAs($user)->get($path)->assertStatus($path === '/login' ? 302 : 200);
            auth('web')->logout();
        }

        $this->post('/csp-report', [])->assertSuccessful();
        // The handoff receiver and the old link.
        $this->get('/auth/ecosystem')->assertRedirect('/login');
        $this->post(route('ecosystem.auth'), [], ['Origin' => 'https://infodot.test'])->assertRedirect('/login');
        // Signing out.
        $this->actingAs($user)->post(route('logout'))->assertRedirect();

        Http::assertNothingSent();
    }

    public function test_a_personal_workspace_and_a_company_infodot_does_not_know_are_not_asked_about(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->infodotAnswers(Http::response($this->platforms(false, 'suspended')));

        $this->actingAs($user)->get(route('me.home'))->assertOk();

        $local = Team::factory()->create(['user_id' => $user->id, 'personal_team' => false]);
        $user->switchTeam($local);
        $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk();

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- personal workspaces

    /** Signs Amara in through InfoDot, then puts her in her local personal workspace with something of her own in it. */
    private function inPersonalWorkspaceAfter(array $teams): User
    {
        $this->redeemed($teams)->assertRedirect(route('dashboard'));

        // What sign-in remembered, carried into the next requests exactly as the browser's session would.
        $remembered = session()->all()[EnsureCompanyEntitled::PERSONAL_ANSWER] ?? null;

        $user = User::where('email', 'amara@example.test')->firstOrFail();
        $user->switchTeam($user->personalTeam());
        $this->infodotAnswers(Http::response($this->platforms(true, 'active')));

        $this->asked = 0;
        $this->actingAs($user->fresh());
        $remembered === null ? session()->forget(EnsureCompanyEntitled::PERSONAL_ANSWER) : session()->put(EnsureCompanyEntitled::PERSONAL_ANSWER, $remembered);

        return $user->fresh();
    }

    public function test_a_personal_workspace_infodot_refused_is_not_a_way_round_the_check(): void
    {
        $user = $this->inPersonalWorkspaceAfter([
            $this->personal(['entitled' => false, 'access' => 'no_personal_access']),
            $this->listed(self::ACME, 'Acme Ltd', ['entitled' => true, 'access' => null]),
        ]);
        $teams = Team::count();

        foreach ([route('me.home'), route('me.profile'), route('me.applications.index'), route('dashboard')] as $url) {
            $response = $this->get($url)->assertStatus(403)
                ->assertSee('Dot.HR needs a company that has switched it on')
                ->assertSee('href="https://infodot.test/platforms/hr"', false)
                ->assertSee('Acme Ltd')
                ->assertSee('action="'.route('current-team.update').'"', false);
            $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action'));
        }

        // Applying and editing the personal profile are stopped too, and nothing is altered or removed.
        $this->post('/jobs/1/apply', [])->assertStatus(403);
        $this->put(route('me.profile.update'), ['headline' => 'Changed'])->assertStatus(403);
        $this->assertSame($teams, Team::count());
        $this->assertNotNull($user->fresh()->personalTeam());

        // The job board stays public, and InfoDot was never asked about the personal workspace.
        $this->get('/jobs')->assertOk();
        $this->assertSame(0, $this->asked);

        // The company that holds the platform can still be switched to.
        $acme = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $this->put(route('current-team.update'), ['team_id' => $acme->id])->assertRedirect();
        $this->get(route('dashboard'))->assertOk();
        $this->assertSame(1, $this->asked);
    }

    public function test_a_team_infodot_does_not_know_follows_the_personal_answer_too(): void
    {
        $user = $this->inPersonalWorkspaceAfter([
            $this->personal(['entitled' => false, 'access' => 'no_personal_access']),
            $this->listed(self::ACME, 'Acme Ltd', ['entitled' => true, 'access' => null]),
        ]);
        $local = Team::factory()->create(['user_id' => $user->id, 'personal_team' => false, 'name' => 'Made Here']);
        $user->switchTeam($local);
        $this->actingAs($user->fresh());

        $this->get(route('dashboard'))->assertStatus(403)->assertSee('Dot.HR needs a company that has switched it on');
        $this->get(route('employees.index'))->assertStatus(403);
        $this->assertSame(0, $this->asked);
    }

    public function test_a_personal_workspace_infodot_marked_entitled_carries_on(): void
    {
        $this->inPersonalWorkspaceAfter([
            $this->personal(['entitled' => true, 'access' => null]),
            $this->listed(self::ACME, 'Acme Ltd', ['entitled' => true, 'access' => null]),
        ]);

        $this->get(route('me.home'))->assertOk();
        $this->get(route('me.applications.index'))->assertOk();
        $this->assertSame(0, $this->asked);
    }

    public function test_a_personal_workspace_carries_on_when_infodot_said_nothing_about_it(): void
    {
        // An older InfoDot: no `entitled` key anywhere.
        $this->inPersonalWorkspaceAfter([$this->personal(), $this->listed(self::ACME, 'Acme Ltd')]);

        $this->assertNull(session(EnsureCompanyEntitled::PERSONAL_ANSWER));
        $this->get(route('me.home'))->assertOk();
        $this->get(route('me.applications.index'))->assertOk();
        $this->assertSame(0, $this->asked);
    }

    /** A real Livewire update request (the bell's toggle), the way the browser sends it. */
    private function bellUpdate(User $user): TestResponse
    {
        $component = Livewire::actingAs($user)->test(NotificationBell::class);
        $snapshot = $component->snapshot;

        return $this->actingAs($user)->postJson('/livewire/update', ['_token' => csrf_token(), 'components' => [[
            'snapshot' => json_encode($snapshot),
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'toggle', 'params' => []]],
        ]]], ['X-Livewire' => 'true']);
    }

    public function test_a_livewire_update_that_worked_is_refused_once_the_answer_expires_and_infodot_says_no(): void
    {
        [$user] = $this->atWork();
        $this->infodotAnswers(Http::response($this->platforms(true, 'active')));

        $this->bellUpdate($user)->assertOk();
        $this->assertSame(1, $this->asked);

        // Still inside the ten minutes: the remembered yes holds, no new call.
        $this->travel(5)->minutes();
        $this->bellUpdate($user)->assertOk();
        $this->assertSame(1, $this->asked);

        // The window passes and InfoDot now says no: the update is aborted, nothing is returned.
        $this->infodotAnswers(Http::response($this->platforms(false, 'suspended')));
        $this->travel(6)->minutes();
        $response = $this->bellUpdate($user)->assertStatus(403);

        $this->assertStringNotContainsString('Notifications', $response->getContent());
        $this->assertSame(2, $this->asked, 'InfoDot was asked again once the window had passed');
    }

    public function test_the_bell_is_not_drawn_on_a_public_page_for_a_refused_person_and_costs_no_call(): void
    {
        [$user] = $this->atWork();
        $this->infodotAnswers(Http::response($this->platforms(false, 'suspended')));

        $this->actingAs($user)->get(route('dashboard'))->assertStatus(403);
        $this->assertSame(1, $this->asked);

        // Now a public page, signed in: the refusal is remembered, so the bell stays out and InfoDot is not asked again.
        $this->actingAs($user)->get('/jobs')->assertOk()->assertDontSee('title="Notifications"', false);
        $this->assertSame(1, $this->asked);
    }

    public function test_the_bell_is_drawn_when_the_company_holds_the_platform(): void
    {
        [$user] = $this->atWork();
        $this->infodotAnswers(Http::response($this->platforms(true, 'active')));

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('title="Notifications"', false);
    }

    public function test_the_flag_off_skips_the_personal_workspace_rule_too(): void
    {
        $this->inPersonalWorkspaceAfter([
            $this->personal(['entitled' => false, 'access' => 'no_personal_access']),
            $this->listed(self::ACME, 'Acme Ltd', ['entitled' => true, 'access' => null]),
        ]);
        $this->get(route('me.home'))->assertStatus(403);

        config(['hr.entitlement_check' => false]);
        $this->get(route('me.home'))->assertOk();
    }

    public function test_a_second_company_that_holds_the_platform_can_still_be_switched_to(): void
    {
        [$user, $acme] = $this->atWork();
        $globex = Team::factory()->create(['user_id' => $user->id, 'personal_team' => false, 'name' => 'Globex']);
        $globex->forceFill(['infodot_team_uuid' => self::GLOBEX])->save();
        Employee::factory()->create(['team_id' => $globex->id, 'first_name' => 'Nomvula', 'last_name' => 'At-Globex']);

        Http::swap(new Factory);
        Http::fake([
            'https://infodot.test/api/ecosystem/companies/'.self::ACME.'/*' => Http::response($this->platforms(false, 'suspended')),
            'https://infodot.test/api/ecosystem/companies/'.self::GLOBEX.'/*' => Http::response($this->platforms(true, 'active', self::GLOBEX)),
        ]);

        // The refusal page offers the person's other workspaces by name, and nothing of the other company's data.
        $this->actingAs($user)->get(route('dashboard'))->assertStatus(403)
            ->assertSee('Globex')->assertSee('Your personal workspace')->assertSee('Sign out')
            ->assertSee('action="'.route('current-team.update').'"', false)
            ->assertDontSee('At-Globex');

        $this->actingAs($user)->put(route('current-team.update'), ['team_id' => $globex->id])->assertRedirect();
        $this->assertSame($globex->id, $user->fresh()->current_team_id);

        $this->actingAs($user->fresh())->get(route('employees.index'))->assertOk()->assertSee('At-Globex')->assertDontSee('Okonkwo-Record');

        // And back: Acme is still refused, with its data untouched.
        $this->actingAs($user->fresh())->put(route('current-team.update'), ['team_id' => $acme->id])->assertRedirect();
        $this->actingAs($user->fresh())->get(route('employees.index'))->assertStatus(403);
        $this->assertSame(1, Employee::withoutGlobalScopes()->where('team_id', $acme->id)->count());
    }

    public function test_access_comes_back_when_infodot_says_so(): void
    {
        [$user] = $this->atWork();
        $this->infodotAnswers(Http::response($this->platforms(false, 'payment_required')));
        $this->actingAs($user)->get(route('employees.index'))->assertStatus(403);

        $this->infodotAnswers(Http::response($this->platforms(true, 'active')));
        $this->travel(11)->minutes();

        $this->actingAs($user)->get(route('employees.index'))->assertOk()->assertSee('Okonkwo-Record');
    }

    public function test_the_refusal_is_never_cached_across_companies(): void
    {
        [$user] = $this->atWork();
        $this->infodotAnswers(Http::response($this->platforms(false, 'suspended')));
        $this->actingAs($user)->get(route('dashboard'))->assertStatus(403);

        $this->assertNotNull(Cache::get('entitlement:hr:'.self::ACME));
        $this->assertNull(Cache::get('entitlement:hr:'.self::GLOBEX));
    }
}
