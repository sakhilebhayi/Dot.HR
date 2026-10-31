<?php

namespace Tests\Feature;

use App\Console\Commands\SchedulerHeartbeat;
use App\Models\Application;
use App\Models\AttendanceRecord;
use App\Models\HrAuditLog;
use App\Models\ProfessionalProfile;
use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\Recruitment\SkillMatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * R-32 Phase 8, enterprise readiness: readiness for monitors, retention for candidate, time
 * and notification data, access-request exports, the restore check, the request ceiling and
 * the N+1 guard.
 */
class EnterpriseReadinessTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    public function test_ready_reports_checks_and_fails_when_the_scheduler_stops(): void
    {
        $this->getJson('/ready')->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.pending_migrations', 0)
            ->assertJsonPath('checks.scheduler.last_run_seconds_ago', null)
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->artisan('hr:heartbeat')->assertSuccessful();
        $this->getJson('/ready')->assertOk()->assertJsonPath('checks.scheduler.ok', true);

        Cache::forever(SchedulerHeartbeat::KEY, now()->subMinutes(20)->getTimestamp());
        $this->getJson('/ready')->assertStatus(503)->assertJsonPath('status', 'fail')->assertJsonPath('checks.scheduler.ok', false);
    }

    public function test_retention_removes_ended_applications_old_time_records_and_read_notifications_only(): void
    {
        $owner = $this->owner();
        $team = $owner->currentTeam;
        $vacancy = new Vacancy;
        $vacancy->forceFill(['team_id' => $team->id, 'title' => 'Clerk', 'description' => 'x', 'work_type' => 'full_time', 'arrangement' => 'on_site', 'pay_basis' => 'undisclosed', 'currency' => 'ZAR', 'openings' => 1, 'status' => 'closed'])->save();

        $make = function (string $stage, int $daysAgo) use ($team, $vacancy): Application {
            $application = new Application;
            $application->forceFill(['team_id' => $team->id, 'vacancy_id' => $vacancy->id, 'user_id' => User::factory()->create()->id, 'stage' => $stage, 'stage_changed_at' => now()->subDays($daysAgo)])->save();

            return $application;
        };
        $oldRejected = $make('rejected', 400);
        DB::table('application_notes')->insert(['team_id' => $team->id, 'application_id' => $oldRejected->id, 'body' => 'Note', 'created_at' => now(), 'updated_at' => now()]);
        $recentRejected = $make('rejected', 30);
        $oldHired = $make('hired', 900);

        $person = $this->employeeIn($team);
        foreach ([1200, 10] as $daysAgo) {
            $record = new AttendanceRecord;
            $record->forceFill(['team_id' => $team->id, 'employee_id' => $person->id, 'clock_in_at' => now()->subDays($daysAgo), 'clock_out_at' => now()->subDays($daysAgo)->addHours(8), 'source' => 'self'])->save();
        }

        foreach ([['read_at' => now()->subDays(200), 'age' => 200], ['read_at' => null, 'age' => 400], ['read_at' => now(), 'age' => 5]] as $n) {
            DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => User::class, 'notifiable_id' => $owner->id, 'data' => '{}', 'read_at' => $n['read_at'], 'created_at' => now()->subDays($n['age']), 'updated_at' => now()]);
        }

        $this->artisan('hr:retention', ['--dry-run' => true])->expectsOutput('Would remove 1 applications')->assertSuccessful();
        $this->assertSame(3, Application::withoutTeamScope(fn () => Application::query()->count()));

        $this->artisan('hr:retention')->expectsOutput('Removed 1 applications')->expectsOutput('Removed 1 attendance records')->expectsOutput('Removed 1 notifications')->assertSuccessful();

        $remaining = Application::withoutTeamScope(fn () => Application::query()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$recentRejected->id, $oldHired->id], $remaining);
        $this->assertSame(0, DB::table('application_notes')->count());
        $this->assertSame(1, AttendanceRecord::withoutTeamScope(fn () => AttendanceRecord::query()->count()));
        $this->assertSame(2, DB::table('notifications')->count());

        $entry = HrAuditLog::withoutTeamScope(fn () => HrAuditLog::query()->where('action', 'retention.applied')->firstOrFail());
        $this->assertNull($entry->actor_user_id);

        config(['hr.candidate_retention_days' => 5]);
        $this->artisan('hr:retention')->assertFailed();
    }

    public function test_a_person_downloads_their_data_and_hr_can_answer_an_access_request(): void
    {
        $owner = $this->owner();
        $team = $owner->currentTeam;
        $staff = $this->memberOf($owner, 'viewer');
        $colleague = $this->memberOf($owner, 'editor');
        $person = $this->employeeIn($team, $staff, ['first_name' => 'Lerato', 'phone' => '082 555 0101']);

        $cycle = new ReviewCycle;
        $cycle->forceFill(['team_id' => $team->id, 'name' => 'H2', 'status' => 'open'])->save();
        $review = new Review;
        $review->forceFill(['team_id' => $team->id, 'review_cycle_id' => $cycle->id, 'employee_id' => $person->id, 'reviewer_id' => $owner->id, 'status' => 'reviewer', 'self_assessment' => 'My reflection text', 'feedback' => 'Draft feedback not yet shared'])->save();

        $mine = $this->actingAs($staff)->get(route('my-work.data'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('attachment; filename="my-data-', $mine->headers->get('Content-Disposition'));
        $data = $mine->json();
        $this->assertSame('082 555 0101', $data['employee_record']['phone']);
        $this->assertSame('My reflection text', $data['reviews'][0]['own_reflection']);
        $this->assertNull($data['reviews'][0]['feedback']);

        $this->actingAs($colleague)->get(route('employees.personal-data', $person))->assertForbidden();
        $hr = $this->actingAs($owner)->get(route('employees.personal-data', $person))->assertOk()->json();
        $this->assertSame('Draft feedback not yet shared', $hr['reviews'][0]['feedback']);

        $actions = HrAuditLog::withoutTeamScope(fn () => HrAuditLog::query()->where('action', 'privacy.exported')->pluck('fields')->all());
        $this->assertCount(2, $actions);
        $this->assertStringNotContainsString('082 555 0101', HrAuditLog::withoutTeamScope(fn () => HrAuditLog::query()->get())->toJson());

        $stranger = $this->owner();
        $this->actingAs($stranger)->get(route('employees.personal-data', $person))->assertNotFound();
    }

    public function test_the_career_export_holds_the_profile_and_applications_to_any_business(): void
    {
        $candidate = User::factory()->withPersonalTeam()->create();
        $profile = ProfessionalProfile::forUser($candidate);
        $profile->skills()->create(['name' => 'Pastel Partner', 'normalized' => SkillMatch::normalise('Pastel Partner'), 'position' => 0]);

        $business = $this->owner();
        $vacancy = new Vacancy;
        $vacancy->forceFill(['team_id' => $business->currentTeam->id, 'title' => 'Bookkeeper', 'description' => 'x', 'work_type' => 'full_time', 'arrangement' => 'on_site', 'pay_basis' => 'undisclosed', 'currency' => 'ZAR', 'openings' => 1, 'status' => 'published'])->save();
        $application = new Application;
        $application->forceFill(['team_id' => $business->currentTeam->id, 'vacancy_id' => $vacancy->id, 'user_id' => $candidate->id, 'stage' => 'shortlisted', 'cover_note' => 'Hello'])->save();

        $data = $this->actingAs($candidate)->get(route('me.data'))->assertOk()->json();
        $this->assertSame(['Pastel Partner'], $data['profile']['skills']);
        $this->assertSame('Bookkeeper', $data['applications'][0]['role']);
        // The candidate sees the stage as they see it in the app, not the employer's internal one.
        $this->assertSame('In review', $data['applications'][0]['stage']);
    }

    public function test_the_restore_check_passes_and_catches_data_encrypted_with_another_key(): void
    {
        $owner = $this->owner();
        $this->employeeIn($owner->currentTeam, null, ['phone' => '082 000 0000']);
        $this->artisan('hr:verify-restore')->expectsOutputToContain('decrypt with this APP_KEY')->assertSuccessful();

        $foreign = new Encrypter(random_bytes(32), 'AES-256-CBC');
        DB::table('employees')->update(['phone' => $foreign->encryptString('082 000 0000')]);
        $this->artisan('hr:verify-restore')->expectsOutputToContain('do NOT decrypt')->assertFailed();
    }

    public function test_signed_in_routes_carry_the_request_ceiling_and_lazy_loading_is_guarded(): void
    {
        foreach (['dashboard', 'my-work.index', 'insights.index', 'employees.index'] as $name) {
            $this->assertContains('throttle:hr', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
        $this->assertTrue(Model::preventsLazyLoading());
    }
}
