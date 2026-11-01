<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ProfessionalProfile;
use App\Models\User;
use App\Models\Vacancy;
use App\Notifications\CandidateUpdateNotification;
use App\Notifications\LifecycleNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * Every Dot.HR notice can also reach people by email, through Dot.Notify (R-32 follow-up). What
 * leaves Dot.HR is the business, the kind of event, a title and a link — never the notice's own
 * text — and nothing at all when Notify is not set up or the business is not linked to InfoDot.
 */
class NoticeEmailsTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    private const URL = 'https://notify.test/api/ingest/events';

    private const UUID = '5b0f2c0e-6a6e-4a43-9d43-3f1c2f0a9e11';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.notify' => ['url' => self::URL, 'secret' => 'notify-ingest-secret-for-tests-0001', 'platform' => 'hr', 'timeout' => 5]]);
        URL::forceRootUrl('http://hr.infodot.co.za');
        Http::swap(new Factory);
        Http::fake([self::URL => Http::response(['id' => 1, 'status' => 'routed'], 202)]);
    }

    /** @return list<array<string, mixed>> */
    private function sent(): array
    {
        return Http::recorded()->map(fn ($pair) => json_decode($pair[0]->body(), true))->values()->all();
    }

    private function linkedOwner(): User
    {
        $owner = $this->owner();
        $owner->currentTeam->forceFill(['infodot_team_uuid' => self::UUID])->save();

        return $owner;
    }

    public function test_a_lifecycle_notice_goes_out_as_a_title_and_a_link_only(): void
    {
        $owner = $this->linkedOwner();
        $staff = $this->memberOf($owner, 'viewer');

        $staff->notify(new LifecycleNotification('Timesheet sent back', 'Your week needs another look: you clocked 14 hours on Sunday.', route('my-work.index'), $owner->currentTeam->id));

        $this->assertCount(1, $sent = $this->sent());
        $this->assertSame('hr.update', $sent[0]['event']);
        $this->assertSame(self::UUID, $sent[0]['company_uuid']);
        $this->assertSame(['email' => $staff->email], $sent[0]['recipient']);
        $this->assertSame(['business_name', 'title', 'url'], array_keys($sent[0]['data']));
        $this->assertSame('Timesheet sent back', $sent[0]['data']['title']);
        $this->assertStringStartsWith('https://', $sent[0]['data']['url']);
        $this->assertStringNotContainsString('14 hours', json_encode($sent[0]));
        $this->assertDatabaseCount('notifications', 1); // the in-app notice is unchanged
    }

    public function test_nothing_leaves_without_notify_or_without_an_infodot_link(): void
    {
        $owner = $this->owner(); // not linked to InfoDot
        $staff = $this->memberOf($owner, 'viewer');
        $staff->notify(new LifecycleNotification('A certificate is about to lapse', 'First aid expires soon.', null, $owner->currentTeam->id));
        $this->assertSame([], $this->sent());

        $owner->currentTeam->forceFill(['infodot_team_uuid' => self::UUID])->save();
        config(['services.notify.url' => '']);
        $staff->notify(new LifecycleNotification('A certificate is about to lapse', 'First aid expires soon.', null, $owner->currentTeam->id));
        $this->assertSame([], $this->sent());
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_a_new_application_reaches_the_business_and_updates_reach_the_candidate(): void
    {
        $owner = $this->linkedOwner();
        $this->actingAs($owner)->post(route('hiring.store'), [
            'title' => 'Bookkeeper', 'description' => 'Keep the books of a growing wholesaler.', 'skills' => 'Pastel Partner, payroll',
            'work_type' => 'full_time', 'arrangement' => 'on_site', 'location' => 'Mahikeng', 'pay_basis' => 'salary',
            'pay_min' => '18000', 'pay_max' => '22000', 'currency' => 'ZAR', 'openings' => 1,
            'closes_on' => now()->addWeeks(2)->toDateString(), 'screening_questions' => 'How many years with Pastel?',
        ])->assertRedirect();
        $vacancy = Vacancy::query()->latest('id')->firstOrFail();
        $this->actingAs($owner)->post(route('hiring.transition', [$vacancy, 'publish']))->assertRedirect();

        $candidate = User::factory()->withPersonalTeam()->create();
        ProfessionalProfile::forUser($candidate);
        $this->actingAs($candidate)->post(route('jobs.apply', $vacancy->id), ['answers' => ['Six years'], 'cover_note' => 'I keep books for a living.', 'share' => '1'])->assertRedirect();

        $received = collect($this->sent())->firstWhere('event', 'application.received');
        $this->assertNotNull($received, 'the business is told a new application arrived');
        $this->assertSame(['email' => $owner->email], $received['recipient']);
        $this->assertSame(['role_title', 'reference', 'url'], array_keys($received['data']));
        $this->assertStringNotContainsString('I keep books', json_encode($received));

        $application = Application::withoutTeamScope(fn () => Application::query()->where('user_id', $candidate->id)->firstOrFail());
        $this->actingAs($owner);
        $candidate->notify(new CandidateUpdateNotification($application->id, 'You are on the shortlist', 'The panel liked your Pastel experience.'));

        $update = collect($this->sent())->firstWhere('event', 'application.updated');
        $this->assertSame(['email' => $candidate->email], $update['recipient']);
        $this->assertSame(['business_name', 'role_title', 'update', 'url'], array_keys($update['data']));
        $this->assertSame('Bookkeeper', $update['data']['role_title']);
        $this->assertStringNotContainsString('panel liked', json_encode($update));
    }

    public function test_leave_notices_are_not_sent_twice(): void
    {
        $owner = $this->linkedOwner();
        $staff = $this->memberOf($owner, 'viewer');
        $employee = $this->employeeIn($owner->currentTeam, $staff);

        $this->actingAs($owner)->post(route('leave-requests.store'), [
            'employee_id' => $employee->id, 'leave_type_id' => $this->leaveType($owner->currentTeam)->id,
            'start_date' => now()->addWeek()->toDateString(), 'end_date' => now()->addWeek()->addDay()->toDateString(), 'reason' => 'Family matter',
        ])->assertRedirect();

        $events = collect($this->sent())->pluck('event')->unique()->values()->all();
        $this->assertSame(['leave.requested'], $events, 'the leave observer sends its own event; the generic forward stays out of it');
    }
}
