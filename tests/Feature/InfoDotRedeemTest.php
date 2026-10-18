<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Production gives every Dot app its own database, so InfoDot's handoff token is
 * not in ours. The app asks InfoDot to prove it, then matches or creates the user.
 */
class InfoDotRedeemTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        config(['ecosystem.infodot' => [
            'redeem_url' => self::URL,
            'platform' => 'hr',
            'secret' => 'service-secret-for-tests',
            'timeout' => 2,
            'provision' => true,
        ]]);
    }

    private function identity(array $user = [], array $extra = []): array
    {
        return array_merge([
            'user' => array_merge(['id' => 7, 'name' => 'Amara Dlamini', 'email' => 'Amara@Example.test', 'email_verified' => true], $user),
            'teams' => [['id' => 3, 'uuid' => self::ACME, 'name' => 'Acme Ltd', 'personal' => false, 'role' => 'owner']],
            'current_team_id' => 3,
            'current_team_uuid' => self::ACME,
        ], $extra);
    }

    public function test_a_person_vouched_for_by_infodot_gets_an_account_syncs_teams_and_is_signed_in(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('ecosystem.auth', ['token' => 'one-time-token']))->assertRedirect(route('dashboard'));

        $user = User::where('email', 'amara@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Amara Dlamini', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->password);
        $this->assertFalse(Hash::check('', $user->password));
        $this->assertSame(1, $user->ownedTeams()->where('personal_team', true)->count(), 'a personal team, as registration gives');

        $acme = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $this->assertSame($acme->id, $user->fresh()->current_team_id, 'InfoDot\'s active company is the current team');
        $this->assertSame($acme->id, auth()->user()->currentTeam->id);
    }

    public function test_the_secret_and_platform_go_in_the_request_and_the_token_never_in_the_url(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('ecosystem.auth', ['token' => 'one-time-token']));

        Http::assertSent(function (Request $request) {
            return $request->url() === self::URL
                && $request->hasHeader('Authorization', 'Bearer service-secret-for-tests')
                && $request['token'] === 'one-time-token'
                && $request['platform'] === 'hr';
        });
    }

    public function test_an_existing_account_is_matched_by_email_without_a_duplicate(): void
    {
        $existing = User::factory()->withPersonalTeam()->create(['email' => 'amara@example.test', 'name' => 'Local Name']);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('ecosystem.auth', ['token' => 't']));

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::where('email', 'amara@example.test')->count());
        $this->assertSame('Local Name', $existing->fresh()->name);
    }

    public function test_an_unverified_local_account_is_adopted_and_its_password_replaced(): void
    {
        $squatter = User::factory()->unverified()->create([
            'email' => 'amara@example.test',
            'password' => Hash::make('attacker-knows-this'),
        ]);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('ecosystem.auth', ['token' => 't']));

        $this->assertAuthenticatedAs($squatter);
        $this->assertFalse(Hash::check('attacker-knows-this', $squatter->fresh()->password));
        $this->assertNotNull($squatter->fresh()->email_verified_at);
    }

    public function test_a_token_infodot_refuses_signs_nobody_in(): void
    {
        Http::fake([self::URL => Http::response(['error' => 'invalid_request'], 403)]);

        $this->get(route('ecosystem.auth', ['token' => 'bad']))->assertForbidden();

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_a_wrong_service_secret_is_a_refusal_not_a_sign_in(): void
    {
        Http::fake([self::URL => Http::response(['error' => 'unauthorized'], 401)]);

        $this->get(route('ecosystem.auth', ['token' => 't']))->assertForbidden();

        $this->assertGuest();
    }

    public function test_an_identity_infodot_does_not_vouch_for_is_refused(): void
    {
        Http::fake([self::URL => Http::response($this->identity(['email_verified' => false]))]);

        $this->get(route('ecosystem.auth', ['token' => 't']))->assertForbidden();

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_an_unverified_identity_is_refused_even_for_an_existing_account(): void
    {
        $existing = User::factory()->withPersonalTeam()->create(['email' => 'amara@example.test']);
        Http::fake([self::URL => Http::response($this->identity(['email_verified' => 'yes']))]);

        $this->get(route('ecosystem.auth', ['token' => 't']))->assertForbidden();

        $this->assertGuest();
        $this->assertSame(1, User::where('id', $existing->id)->count());
    }

    public function test_a_reply_without_a_user_signs_nobody_in(): void
    {
        Http::fake([self::URL => Http::response(['teams' => []])]);

        $this->get(route('ecosystem.auth', ['token' => 't']))->assertForbidden();

        $this->assertGuest();
    }

    public function test_when_infodot_is_down_the_person_is_told_to_retry_not_that_they_are_forbidden(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);
        $this->get(route('ecosystem.auth', ['token' => 't']))->assertStatus(503);

        Http::fake([self::URL => Http::response('slow down', 429)]);
        $this->get(route('ecosystem.auth', ['token' => 't']))->assertStatus(503);

        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->get(route('ecosystem.auth', ['token' => 't']))->assertStatus(503);

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_without_a_secret_the_old_local_only_behaviour_remains_and_infodot_is_never_called(): void
    {
        config(['ecosystem.infodot.secret' => null]);
        Http::fake();

        $this->get(route('ecosystem.auth', ['token' => 'unknown']))->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_token_in_our_own_database_still_works_and_is_spent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $user->createToken('handoff', ['ecosystem:read'])->plainTextToken;
        Http::fake();

        $this->get(route('ecosystem.auth', ['token' => $token]))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        Http::assertNothingSent();
        $this->assertSame(0, PersonalAccessToken::count());

        auth('web')->logout();
        $this->get(route('ecosystem.auth', ['token' => $token]))->assertForbidden();
    }

    public function test_a_local_token_without_the_ecosystem_ability_is_refused(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $user->createToken('api', ['read'])->plainTextToken;
        Http::fake();

        $this->get(route('ecosystem.auth', ['token' => $token]))->assertForbidden();

        $this->assertGuest();
    }

    public function test_provisioning_can_be_switched_off(): void
    {
        config(['ecosystem.infodot.provision' => false]);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('ecosystem.auth', ['token' => 't']))->assertForbidden();

        $this->assertSame(0, User::count());
    }

    public function test_a_missing_empty_or_non_string_token_is_forbidden_without_calling_infodot(): void
    {
        Http::fake();

        $this->get(route('ecosystem.auth'))->assertForbidden();
        $this->get('/auth/ecosystem?token=')->assertForbidden();
        $this->get('/auth/ecosystem?token[]=x')->assertForbidden();
        $this->get('/auth/ecosystem?token[a][b]=x')->assertForbidden();

        Http::assertNothingSent();
        $this->assertGuest();
    }

    #[DataProvider('hostileRedirects')]
    public function test_an_open_redirect_attempt_falls_back_to_the_dashboard(string $redirect): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('ecosystem.auth', ['token' => 't', 'redirect' => $redirect]))->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
    }

    public static function hostileRedirects(): array
    {
        return [
            'absolute url' => ['https://evil.test/phish'],
            'protocol relative' => ['//evil.test/phish'],
            'backslash trick' => ['/\\evil.test'],
            'no leading slash' => ['evil.test'],
            'javascript scheme' => ['javascript:alert(1)'],
            'traversal' => ['/employees/../../evil'],
            'encoded traversal' => ['/%2e%2e/evil'],
            'encoded double slash' => ['/%2f/evil.test'],
            'double encoded double slash' => ['/%252f%252fevil.test'],
            'control character' => ["/dashboard\r\nLocation: https://evil.test"],
            'path with double slash' => ['/a//b'],
            'empty' => [''],
        ];
    }

    public function test_a_plain_path_in_this_app_is_honoured(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('ecosystem.auth', ['token' => 't', 'redirect' => '/employees']))->assertRedirect('/employees');
    }

    public function test_an_array_redirect_falls_back(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get('/auth/ecosystem?token=t&redirect[]=/employees')->assertRedirect(route('dashboard'));
    }

    public function test_signing_in_replaces_the_session_id(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);
        $this->startSession();
        $before = session()->getId();

        $this->get(route('ecosystem.auth', ['token' => 't']))->assertRedirect();

        $this->assertNotSame($before, session()->getId());
    }

    public function test_the_endpoint_is_throttled(): void
    {
        Http::fake([self::URL => Http::response(['error' => 'invalid_request'], 403)]);

        for ($i = 0; $i < 10; $i++) {
            $this->get(route('ecosystem.auth', ['token' => 'guess-'.$i]))->assertForbidden();
        }

        $this->get(route('ecosystem.auth', ['token' => 'guess-11']))->assertStatus(429);
        Http::assertSentCount(10);
    }

    public function test_a_person_with_a_stale_current_team_is_moved_to_one_they_belong_to(): void
    {
        $someoneElses = Team::factory()->create(['personal_team' => false]);
        $existing = User::factory()->withPersonalTeam()->create(['email' => 'amara@example.test']);
        $existing->forceFill(['current_team_id' => $someoneElses->id])->save();
        Http::fake([self::URL => Http::response($this->identity(['email' => 'amara@example.test'], ['teams' => [], 'current_team_uuid' => null]))]);

        $this->get(route('ecosystem.auth', ['token' => 't']))->assertRedirect();

        $this->assertSame($existing->personalTeam()->id, $existing->fresh()->current_team_id);
    }

    public function test_after_sign_in_a_person_in_company_a_sees_none_of_company_bs_employees(): void
    {
        $acme = Team::forceCreate(['user_id' => User::factory()->create()->id, 'name' => 'Acme Ltd', 'personal_team' => false])
            ->forceFill(['infodot_team_uuid' => self::ACME]);
        $acme->save();
        $globex = Team::forceCreate(['user_id' => User::factory()->create()->id, 'name' => 'Globex Corp', 'personal_team' => false])
            ->forceFill(['infodot_team_uuid' => self::GLOBEX]);
        $globex->save();

        $mine = Employee::create(['team_id' => $acme->id, 'first_name' => 'Ayanda', 'last_name' => 'Acme', 'employment_type' => 'full_time', 'status' => 'active']);
        $theirs = Employee::create(['team_id' => $globex->id, 'first_name' => 'Gugu', 'last_name' => 'Globex', 'employment_type' => 'full_time', 'status' => 'active']);

        Http::fake([self::URL => Http::response($this->identity(
            ['email' => 'dan@acme.test'],
            ['teams' => [['id' => 3, 'uuid' => self::ACME, 'name' => 'Acme Ltd', 'personal' => false, 'role' => 'editor']], 'current_team_uuid' => self::ACME],
        ))]);

        $this->get(route('ecosystem.auth', ['token' => 't']))->assertRedirect();

        $this->assertSame(1, Employee::count());
        $this->assertSame('Ayanda', Employee::first()->first_name);
        $this->get(route('employees.show', $mine))->assertOk()->assertSee('Ayanda');
        $this->get(route('employees.show', $theirs))->assertNotFound();
        $this->get(route('dashboard'))->assertOk();
    }
}
