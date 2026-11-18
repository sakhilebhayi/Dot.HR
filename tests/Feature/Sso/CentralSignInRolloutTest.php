<?php

namespace Tests\Feature\Sso;

use App\Mail\SsoClaimCodeMail;
use App\Models\Team;
use App\Models\User;
use App\Sso\HrProvisioner;
use Dot\SsoClient\Tests\Support\FakeIdp;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Jetstream\Mail\TeamInvitation;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * R-54 rollout: Dot.HR behind DOTSSO_ENABLED. Off, nothing changes; on, the sign-in and registration entries go to InfoDot while
 * the public job board, public profile links, probes and Sanctum stay exactly as they are, and employee linking still runs.
 */
class CentralSignInRolloutTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    private FakeIdp $idp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->idp = (new FakeIdp)->install();
        config([
            'dotsso.provisioner' => HrProvisioner::class,
            'dotsso.enabled' => false,
        ]);
        Mail::fake();
    }

    private function on(): void
    {
        config(['dotsso.enabled' => true]);
    }

    private function signInVia(array $claims = [], array $session = [])
    {
        $response = $this->withSession($session)->get('/sso/login')->assertRedirect();
        $authorize = FakeIdp::authorizeParams($response->headers->get('Location'));
        $code = $this->idp->approve($authorize, $claims);

        return $this->get('/sso/callback?'.http_build_query(['code' => $code, 'state' => $authorize['state']]));
    }

    public function test_the_users_table_has_a_nullable_unique_infodot_user_uuid(): void
    {
        $a = User::factory()->create();
        $this->assertNull($a->infodot_user_uuid);
        User::factory()->create();

        $a->forceFill(['infodot_user_uuid' => 'x'])->save();
        $this->expectException(QueryException::class);
        User::factory()->create()->forceFill(['infodot_user_uuid' => 'x'])->save();
    }

    public function test_with_the_flag_off_nothing_changes(): void
    {
        $this->get('/login')->assertOk()->assertSee('Remember me');
        $this->get('/sso/login')->assertNotFound();
        $this->get('/')->assertOk()->assertSee(route('login'), false)->assertDontSee('/sso/login', false);
        $this->get('/sso/callback')->assertNotFound();
    }

    public function test_with_the_flag_on_the_local_pages_go_to_infodot_sign_in(): void
    {
        $this->on();

        $this->get('/login')->assertRedirect(route('dotsso.login'));
        $this->get('/register')->assertRedirect(route('dotsso.login', ['screen' => 'register']));
        $this->get('/forgot-password')->assertRedirect(route('dotsso.login'));
        $this->post('/login', ['email' => 'a@b.test', 'password' => 'x'])->assertRedirect(route('dotsso.login'));
    }

    public function test_the_signed_out_pages_link_straight_to_the_central_sign_in(): void
    {
        $this->on();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(route('dotsso.login'), $html);
        $this->assertStringContainsString(route('dotsso.login', ['screen' => 'register']), $html);
        $this->assertStringNotContainsString('href="'.route('login').'"', $html);
    }

    public function test_the_break_glass_switch_brings_the_local_login_back(): void
    {
        $this->on();
        config(['dotsso.allow_local_login' => true]);

        $this->get('/login')->assertOk()->assertSee('Remember me');
    }

    public function test_a_signed_out_deep_link_survives_the_round_trip(): void
    {
        $this->on();
        $this->get('/me/applications')->assertRedirect(route('login'));
        $this->get('/login')->assertRedirect(route('dotsso.login'));

        $this->signInVia()->assertRedirect('/me/applications');
        $this->assertAuthenticated();
    }

    public function test_sign_in_to_apply_returns_to_the_vacancy_page_not_the_dashboard(): void
    {
        $this->on();

        $this->get(route('jobs.signin', 4321))->assertRedirect(route('dotsso.login'));
        $this->signInVia()->assertRedirect(route('jobs.show', 4321));
        $this->assertAuthenticated();
    }

    public function test_sign_in_to_apply_with_the_flag_off_goes_to_the_local_form_and_remembers_the_vacancy(): void
    {
        $this->get(route('jobs.signin', 4321))->assertRedirect(route('login'));
        $this->assertSame(route('jobs.show', 4321), session('url.intended'));
    }

    public function test_the_public_job_board_and_profile_links_stay_outside_the_central_login(): void
    {
        $this->on();

        $this->get('/jobs')->assertOk();
        $this->get('/jobs/999999')->assertNotFound();
        $this->get('/people/abcdefghij')->assertNotFound();
        $this->get('/ready')->assertSuccessful();
    }

    public function test_employee_linking_by_verified_email_still_runs_when_sso_signs_a_person_in(): void
    {
        $this->on();
        $owner = $this->owner();
        $member = $this->memberOf($owner, 'viewer');
        $member->forceFill(['infodot_user_uuid' => 'infodot-emp-1'])->save();
        $employee = $this->employeeIn($owner->currentTeam, null, ['email' => $member->email]);

        $this->signInVia(['sub' => 'infodot-emp-1', 'email' => $member->email])->assertRedirect('/dashboard');

        $this->assertSame($member->id, $employee->fresh()->user_id);
    }

    public function test_an_admin_made_employee_link_survives_linking_the_account_to_infodot(): void
    {
        $this->on();
        $owner = $this->owner();
        $member = $this->memberOf($owner, 'viewer');
        $employee = $this->employeeIn($owner->currentTeam, $member, ['email' => 'someone.else@example.test']);
        $users = User::count();

        $this->signInVia(['sub' => 'infodot-emp-2', 'email' => $member->email])->assertRedirect('/dashboard');

        $this->assertSame($member->id, $employee->fresh()->user_id);
        $this->assertSame('infodot-emp-2', $member->fresh()->infodot_user_uuid);
        $this->assertSame($users, User::count(), 'no second user is made');
    }

    public function test_a_first_sign_in_creates_the_account_with_the_infodot_id_and_a_personal_workspace(): void
    {
        $this->on();

        $this->signInVia(['sub' => 'infodot-uuid-9', 'email' => 'new@example.test', 'name' => 'Nia New'])->assertRedirect('/dashboard');

        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('infodot-uuid-9', $user->infodot_user_uuid);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Team::where('user_id', $user->id)->where('personal_team', true)->exists());
        $this->get('/dashboard')->assertOk();
    }

    public function test_a_verified_person_with_a_personal_workspace_is_linked_not_duplicated(): void
    {
        $this->on();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'solo@example.test', 'email_verified_at' => now()]);

        $this->signInVia(['sub' => 'infodot-solo', 'email' => 'solo@example.test'])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
        $this->assertSame('infodot-solo', $user->fresh()->infodot_user_uuid);
    }

    public function test_an_unverified_local_user_claims_with_a_code_and_keeps_data_and_password(): void
    {
        $this->on();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'ada@example.test', 'email_verified_at' => null, 'password' => Hash::make('old-password-1')]);
        $team = $user->ownedTeams()->first();
        $hash = $user->password;

        $this->signInVia(['sub' => 'infodot-uuid-5'])->assertRedirect(route('dotsso.claim'));
        $this->assertGuest();

        $sent = null;
        Mail::assertSent(SsoClaimCodeMail::class, function ($m) use (&$sent) {
            $sent = $m->code;

            return $m->hasTo('ada@example.test');
        });

        $this->get('/sso/claim')->assertOk()->assertSee('Confirm it is you')->assertSee('a**@example.test', false)->assertDontSee($sent);
        $this->post('/sso/claim', ['code' => $sent])->assertRedirect('/dashboard');

        $user->refresh();
        $this->assertSame('infodot-uuid-5', $user->infodot_user_uuid);
        $this->assertSame($hash, $user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($team->fresh()->is($user->ownedTeams()->first()), 'the team stays on the user');
        $this->assertSame(1, User::count());
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_claim_page_is_one_form_with_a_labelled_code_field_and_real_buttons(): void
    {
        $this->on();
        User::factory()->create(['email' => 'ada@example.test', 'email_verified_at' => null]);
        $this->signInVia()->assertRedirect(route('dotsso.claim'));

        $html = $this->get('/sso/claim')->assertOk()->getContent();

        $this->assertStringContainsString('autocomplete="one-time-code"', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertMatchesRegularExpression('/<label[^>]+for="claim-code"/', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_sanctum_api_access_is_untouched_while_sso_is_on(): void
    {
        $this->on();
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/user')->assertOk()->assertJsonPath('email', $user->email);
    }

    public function test_a_real_sanctum_bearer_token_works_while_sso_is_on(): void
    {
        $this->on();
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->getJson('/api/user')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/user')->assertOk();
    }

    public function test_probes_and_the_csp_report_are_never_redirected_while_sso_is_on(): void
    {
        $this->on();

        $this->post('/csp-report', [])->assertStatus(204);
        $this->get('/ready')->assertSuccessful();
        $this->get('/up')->assertOk();
    }

    public function test_the_fallback_receiver_stays_and_a_get_goes_to_the_central_sign_in(): void
    {
        $this->on();

        $this->get('/auth/ecosystem')->assertRedirect(route('dotsso.login'));
    }

    public function test_the_stored_infodot_id_wins_over_a_changed_email(): void
    {
        $this->on();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'old@example.test', 'email_verified_at' => now(), 'infodot_user_uuid' => 'infodot-u-3']);

        $this->signInVia(['sub' => 'infodot-u-3', 'email' => 'new@example.test'])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
    }

    public function test_the_error_page_has_one_h1_a_reference_and_a_way_back(): void
    {
        $this->on();

        $html = $this->get('/sso/callback?error=access_denied&state=x')->assertStatus(400)->getContent();

        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('Back to Dot.HR', $html);
        $this->assertStringContainsString('Reference:', $html);
    }

    public function test_the_error_page_actions_lead_to_the_central_sign_in(): void
    {
        $this->on();

        $this->get('/no-such-page-here')->assertNotFound()->assertSee(route('dotsso.login'), false);
    }

    public function test_the_team_invitation_mail_points_new_people_at_the_central_registration(): void
    {
        $this->on();
        $team = Team::forceCreate(['user_id' => User::factory()->create()->id, 'name' => 'People Co', 'personal_team' => false]);
        $invitation = $team->teamInvitations()->create(['email' => 'x@example.test', 'role' => 'editor']);
        $mail = (new TeamInvitation($invitation))->render();

        $this->assertStringContainsString(route('dotsso.login', ['screen' => 'register']), $mail);
    }

    public function test_a_back_channel_logout_ends_a_signed_in_session_even_though_the_routes_use_auth_sanctum(): void
    {
        $this->on();
        $this->signInVia(['sub' => 'infodot-bc-1', 'email' => 'bc@example.test'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();

        DB::table('dotsso_revocations')->insert(['sub' => 'infodot-bc-1', 'revoked_at_ms' => (int) floor(microtime(true) * 1000) + 5000, 'created_at' => now(), 'updated_at' => now()]);

        $this->get('/dashboard')->assertRedirect();
        $this->assertFalse(session()->has('dotsso.session'), 'the SSO session was invalidated');
        $this->assertSame('web', config('dotsso.guard'), 'the package default session guard is used, not hand-set here');
    }
}
