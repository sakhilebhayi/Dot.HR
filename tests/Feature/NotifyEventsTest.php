<?php

namespace Tests\Feature;

use App\Jobs\SendNotifyEvent;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Team;
use App\Models\User;
use App\Notifications\LeaveRequestSubmittedNotification;
use App\Services\Notify\NotifyClient;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * Dot.HR tells Dot.Notify about the leave events it already raises in-app. Notify is a shared service: nothing
 * here may ever break or slow a user action, nothing is sent unless it is configured, and leave data is
 * person-sensitive, so the payload carries the bare minimum (never the reason, contact details or balances).
 */
class NotifyEventsTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    private const URL = 'https://notify.test/api/ingest/events';

    private const SECRET = 'notify-ingest-secret-for-tests-0001';

    private const UUID = '5b0f2c0e-6a6e-4a43-9d43-3f1c2f0a9e11';

    private const REASON = 'Chemotherapy follow-up and migraine clinic';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-02 09:00:00');
        Cache::flush();
        config(['services.notify' => ['url' => self::URL, 'secret' => self::SECRET, 'platform' => 'hr', 'timeout' => 5]]);
        URL::forceRootUrl('http://hr.infodot.co.za'); // as a dev .env would have it: the client must still send https
        $this->fakeNotify([self::URL => Http::response(['id' => 1, 'status' => 'routed'], 202)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Replaces any earlier stub (Http::fake only ever adds, and the first match wins) and clears the record. */
    private function fakeNotify(mixed $stub): void
    {
        Http::swap(new Factory);
        Http::fake($stub);
    }

    /** @return object{owner: User, admin: User, editor: User, staff: User, employee: Employee, team: Team} */
    private function linked(): object
    {
        $owner = User::factory()->withPersonalTeam()->create(['name' => 'Olivia Owner Fixture']);
        $team = $owner->currentTeam;
        $team->forceFill(['infodot_team_uuid' => self::UUID])->save();
        $admin = $this->memberOf($owner, 'admin');
        $admin->forceFill(['name' => 'Adam Admin Fixture'])->save();
        $editor = $this->memberOf($owner, 'editor');
        $staff = $this->memberOf($owner, 'viewer');
        $staff->forceFill(['name' => 'Thandi Staff Fixture'])->save();
        $employee = $this->employeeIn($team, $staff, [
            'first_name' => 'Thandi', 'last_name' => 'Mokoena',
            'email' => 'thandi.work.private@employer.test', 'phone' => '+27 82 555 0199',
        ]);

        return (object) compact('owner', 'admin', 'editor', 'staff', 'employee', 'team');
    }

    /** @return array<string, mixed> */
    private function sentJson(int $index = 0): array
    {
        return json_decode(Http::recorded()->values()->all()[$index][0]->body(), true);
    }

    private function rawBody(int $index = 0): string
    {
        return Http::recorded()->values()->all()[$index][0]->body();
    }

    /** @return list<string> the recipient addresses of everything sent, sorted */
    private function recipients(): array
    {
        $emails = Http::recorded()->map(fn ($pair) => json_decode($pair[0]->body(), true)['recipient']['email'])->values()->all();
        sort($emails);

        return $emails;
    }

    private function submit(object $f, array $extra = []): void
    {
        $this->actingAs($f->owner)->post(route('leave-requests.store'), $extra + [
            'employee_id' => $f->employee->id,
            'leave_type_id' => $this->leaveType($f->team)->id,
            'start_date' => '2026-03-09',
            'end_date' => '2026-03-10',
            'reason' => self::REASON,
        ])->assertRedirect(route('leave-requests.index'));
    }

    private function pending(object $f): LeaveRequest
    {
        return $this->leaveFor($f->employee, '2026-03-09', '2026-03-10', ['reason' => self::REASON]);
    }

    /** @param  list<string>  $a @return list<string> */
    private function sorted(array $a): array
    {
        sort($a);

        return $a;
    }

    private function assertCommonShape(array $json, string $event, string $email, array $dataKeys): void
    {
        $this->assertSame(['company_uuid', 'data', 'event', 'idempotency_key', 'platform', 'recipient'], $this->sorted(array_keys($json)));
        $this->assertSame('hr', $json['platform']);
        $this->assertSame(self::UUID, $json['company_uuid']);
        $this->assertSame($event, $json['event']);
        $this->assertSame(['email' => $email], $json['recipient']);
        $this->assertSame($this->sorted($dataKeys), $this->sorted(array_keys($json['data'])), 'only the catalogue keys');
        $this->assertLessThanOrEqual(120, strlen($json['idempotency_key']));
        $this->assertStringStartsWith("{$event}:", $json['idempotency_key']);
        $this->assertStringContainsString(':'.sha1($email).':', $json['idempotency_key']);

        $url = $json['data']['url'];
        $this->assertStringStartsWith('https://', $url);
        $this->assertSame('hr.infodot.co.za', parse_url($url, PHP_URL_HOST));
        $this->assertSame(1, substr_count(json_encode($json), $email), 'the address appears once, in recipient.email only');
    }

    // ------------------------------------------------------------------ leave.requested

    public function test_a_new_request_posts_a_signed_leave_requested_event_to_each_approver_and_nobody_else(): void
    {
        $f = $this->linked();

        $this->submit($f);

        Http::assertSentCount(2);
        $this->assertSame($this->sorted([$f->owner->email, $f->admin->email]), $this->recipients(), 'owner and admin only, not the editor or the employee');
        Http::assertSent(function (Request $request) {
            $body = $request->body();
            $timestamp = $request->header('X-InfoDot-Timestamp')[0] ?? '';

            return $request->url() === self::URL
                && $request->method() === 'POST'
                && ($request->header('X-InfoDot-Signature')[0] ?? '') === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET)
                && $timestamp === (string) now()->timestamp
                && ! str_contains($body, self::SECRET);
        });

        $leave = LeaveRequest::withoutGlobalScopes()->firstOrFail();
        $json = $this->sentJson();
        $this->assertCommonShape($json, 'leave.requested', $json['recipient']['email'], ['employee_name', 'leave_type', 'start_date', 'end_date', 'days', 'url']);
        $this->assertSame('Thandi Mokoena', $json['data']['employee_name']);
        $this->assertSame($this->leaveType($f->team)->name, $json['data']['leave_type']);
        $this->assertSame('2026-03-09', $json['data']['start_date']);
        $this->assertSame('2026-03-10', $json['data']['end_date']);
        $this->assertSame('2', $json['data']['days']);
        $this->assertSame('https://hr.infodot.co.za/leave-requests/'.$leave->id, $json['data']['url']);
        $this->assertStringStartsWith('leave.requested:'.$leave->id.':'.sha1($json['recipient']['email']).':', $json['idempotency_key']);
    }

    public function test_the_payload_never_carries_the_reason_contact_details_or_balances(): void
    {
        $f = $this->linked();

        $this->submit($f);

        foreach ([0, 1] as $i) {
            $raw = $this->rawBody($i);
            $this->assertStringNotContainsString('Chemotherapy', $raw);
            $this->assertStringNotContainsString('migraine', $raw);
            $this->assertStringNotContainsString('thandi.work.private', $raw, 'the employee work email');
            $this->assertStringNotContainsString($f->staff->email, $raw, 'the employee account email');
            $this->assertStringNotContainsString('555 0199', $raw, 'the employee phone');
            foreach (['reason', 'phone', 'balance', 'entitlement', 'remaining', 'available'] as $word) {
                $this->assertStringNotContainsString($word, strtolower($raw));
            }
        }
    }

    public function test_a_half_day_request_reports_half_a_day(): void
    {
        $f = $this->linked();

        $this->submit($f, ['end_date' => '2026-03-09', 'half_day' => 'am']);

        $this->assertSame('0.5', $this->sentJson()['data']['days']);
    }

    public function test_the_observer_sends_for_a_request_created_any_other_way_but_not_for_later_edits(): void
    {
        $f = $this->linked();

        $leave = $this->pending($f);
        Http::assertSentCount(2);

        $leave->update(['half_day' => null, 'status' => 'cancelled']);
        Http::assertSentCount(2);
    }

    public function test_in_app_notifications_to_approvers_are_unchanged(): void
    {
        $f = $this->linked();

        $this->submit($f);

        $this->assertSame(1, $f->owner->notifications()->where('type', LeaveRequestSubmittedNotification::class)->count());
        $this->assertSame(1, $f->admin->notifications()->where('type', LeaveRequestSubmittedNotification::class)->count());
        $this->assertSame(0, $f->editor->notifications()->count());
        $this->assertSame(0, $f->staff->notifications()->count());
    }

    // ------------------------------------------------------------------ leave.decided

    public function test_approving_posts_leave_decided_to_the_employee_only(): void
    {
        $f = $this->linked();
        $leave = $this->pending($f);
        $this->fakeNotify([self::URL => Http::response([], 202)]);

        $this->actingAs($f->admin)->post(route('leave-requests.approve', $leave))->assertRedirect();

        $this->assertSame('approved', $leave->fresh()->status);
        Http::assertSentCount(1);
        $json = $this->sentJson();
        $this->assertCommonShape($json, 'leave.decided', $f->staff->email, ['leave_type', 'start_date', 'end_date', 'decision', 'decided_by', 'url']);
        $this->assertSame('approved', $json['data']['decision']);
        $this->assertSame('Adam Admin Fixture', $json['data']['decided_by']);
        $this->assertSame('2026-03-09', $json['data']['start_date']);
        $this->assertSame($this->leaveType($f->team)->name, $json['data']['leave_type']);
        $this->assertSame('https://hr.infodot.co.za/leave-requests/'.$leave->id, $json['data']['url']);
        $this->assertSame('leave.decided:'.$leave->id.':'.sha1($f->staff->email).':approved', $json['idempotency_key']);
        $this->assertStringNotContainsString('Chemotherapy', $this->rawBody());
        $this->assertStringNotContainsString('thandi.work.private', $this->rawBody());
    }

    public function test_denying_posts_a_denied_decision(): void
    {
        $f = $this->linked();
        $leave = $this->pending($f);
        $this->fakeNotify([self::URL => Http::response([], 202)]);

        $this->actingAs($f->owner)->post(route('leave-requests.deny', $leave))->assertRedirect();

        $this->assertSame('denied', $leave->fresh()->status);
        $json = $this->sentJson();
        $this->assertSame('denied', $json['data']['decision']);
        $this->assertSame('Olivia Owner Fixture', $json['data']['decided_by']);
        $this->assertStringEndsWith(':denied', $json['idempotency_key']);
    }

    public function test_a_refused_second_decision_sends_nothing_more(): void
    {
        $f = $this->linked();
        $leave = $this->pending($f);
        $this->fakeNotify([self::URL => Http::response([], 202)]);

        $this->actingAs($f->admin)->post(route('leave-requests.approve', $leave))->assertRedirect();
        $this->actingAs($f->owner)->post(route('leave-requests.deny', $leave))->assertSessionHas('error');

        Http::assertSentCount(1);
    }

    public function test_an_employee_with_no_user_account_or_no_email_gets_no_decision_event(): void
    {
        $f = $this->linked();
        $unlinked = $this->employeeIn($f->team, null, ['first_name' => 'Nobody', 'last_name' => 'Linked']);
        $leave = $this->leaveFor($unlinked, '2026-03-16', '2026-03-17');
        $this->fakeNotify([self::URL => Http::response([], 202)]);

        $this->actingAs($f->admin)->post(route('leave-requests.approve', $leave))->assertRedirect();
        $this->assertSame('approved', $leave->fresh()->status);

        $noEmail = $this->leaveFor($f->employee, '2026-03-23', '2026-03-24');
        $f->staff->forceFill(['email' => ''])->save();
        $this->fakeNotify([self::URL => Http::response([], 202)]);
        $this->actingAs($f->admin)->post(route('leave-requests.deny', $noEmail))->assertRedirect();
        $this->assertSame('denied', $noEmail->fresh()->status);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------- when not to send

    public function test_it_is_disabled_when_the_url_or_the_secret_is_blank(): void
    {
        $f = $this->linked();
        Log::spy();

        foreach ([['url' => ''], ['secret' => ''], ['url' => null, 'secret' => null]] as $blank) {
            config(['services.notify' => array_merge(config('services.notify'), $blank)]);
            $this->assertFalse(app(NotifyClient::class)->enabled());
            $leave = $this->pending($f);
            $this->actingAs($f->admin)->post(route('leave-requests.approve', $leave))->assertRedirect();
            $this->assertSame('approved', $leave->fresh()->status);
        }

        Http::assertNothingSent();
        Log::shouldHaveReceived('debug')->withArgs(fn ($message) => str_contains($message, 'disabled'));

        Queue::fake();
        $this->pending($f);
        Queue::assertNothingPushed();
    }

    public function test_a_team_not_linked_to_infodot_is_skipped(): void
    {
        $f = $this->linked();
        $f->team->forceFill(['infodot_team_uuid' => null])->save();

        $leave = $this->pending($f);
        $this->actingAs($f->admin)->post(route('leave-requests.approve', $leave))->assertRedirect();

        Http::assertNothingSent();
        $this->assertSame(1, $f->owner->notifications()->count(), 'in-app still sent');
    }

    public function test_an_approver_with_no_email_is_skipped_but_the_others_still_get_theirs(): void
    {
        $f = $this->linked();
        $f->admin->forceFill(['email' => ''])->save();

        $this->pending($f);

        Http::assertSentCount(1);
        $this->assertSame([$f->owner->email], $this->recipients());
        $this->assertSame(1, $f->admin->notifications()->count(), 'in-app still sent');
    }

    // ------------------------------------------------------------- Notify misbehaving

    /** @return array<string, array{0: callable}> */
    public static function failures(): array
    {
        return [
            '500' => [fn () => Http::response('boom', 500)],
            '429' => [fn () => Http::response('slow down', 429)],
            '401' => [fn () => Http::response('no', 401)],
            'timeout' => [fn () => fn () => throw new ConnectionException('cURL error 28: Operation timed out')],
            'unexpected exception' => [fn () => fn () => throw new \RuntimeException('kaboom')],
        ];
    }

    #[DataProvider('failures')]
    public function test_a_notify_failure_changes_neither_the_user_action_nor_the_in_app_notification(callable $failure): void
    {
        $this->fakeNotify([self::URL => $failure()]);
        $f = $this->linked();

        $this->submit($f);
        $leave = LeaveRequest::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('pending', $leave->status);
        $this->assertSame(1, $f->owner->notifications()->where('type', LeaveRequestSubmittedNotification::class)->count());
        $this->assertSame(1, $f->admin->notifications()->where('type', LeaveRequestSubmittedNotification::class)->count());

        $this->actingAs($f->admin)->post(route('leave-requests.approve', $leave))->assertRedirect()->assertSessionHas('status');
        $this->assertSame('approved', $leave->fresh()->status);
        $this->assertSame($f->admin->id, $leave->fresh()->reviewed_by);
    }

    public function test_a_failure_to_even_queue_the_event_never_reaches_the_caller(): void
    {
        $f = $this->linked();
        $this->mock(Dispatcher::class, fn ($mock) => $mock->shouldReceive('dispatch')->andThrow(new \RuntimeException('queue backend down')));

        $this->submit($f);

        $this->assertSame(1, LeaveRequest::withoutGlobalScopes()->count());
        $this->assertSame(1, $f->owner->notifications()->count());
    }

    public function test_the_event_is_queued_after_commit_and_not_sent_inside_the_request(): void
    {
        Queue::fake();
        $f = $this->linked();

        $this->submit($f);

        Queue::assertPushed(SendNotifyEvent::class, 2);
        Http::assertNothingSent();

        $job = new SendNotifyEvent(['event' => 'x']);
        $this->assertTrue($job->afterCommit);
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300], $job->backoff());
    }

    public function test_the_queued_payload_is_fixed_at_dispatch_so_the_worker_needs_no_team_context(): void
    {
        Queue::fake();
        $f = $this->linked();

        $this->submit($f);

        Queue::assertPushed(SendNotifyEvent::class, function (SendNotifyEvent $job) {
            return $job->payload['data']['employee_name'] === 'Thandi Mokoena' && ! str_contains(json_encode($job->payload), 'Chemotherapy');
        });
    }

    public function test_the_job_never_throws_whatever_the_client_does(): void
    {
        $this->fakeNotify(fn () => throw new \RuntimeException('unexpected'));

        (new SendNotifyEvent(['platform' => 'hr', 'event' => 'leave.requested']))->handle(app(NotifyClient::class));

        $broken = \Mockery::mock(NotifyClient::class);
        $broken->shouldReceive('deliver')->andThrow(new \RuntimeException('client exploded'));
        (new SendNotifyEvent(['event' => 'leave.requested']))->handle($broken);

        $this->assertTrue(true);
    }

    public function test_the_job_asks_the_queue_to_retry_with_backoff_then_stops_after_three_tries(): void
    {
        $this->fakeNotify([self::URL => Http::response('x', 503)]);
        $payload = ['platform' => 'hr', 'event' => 'leave.requested', 'company_uuid' => self::UUID, 'recipient' => ['email' => 'a@b.test'], 'data' => [], 'idempotency_key' => 'k'];

        $release = [];
        foreach ([1, 2, 3] as $attempt) {
            $queueJob = \Mockery::mock(QueueJob::class);
            $queueJob->shouldReceive('attempts')->andReturn($attempt);
            $queueJob->shouldReceive('release')->andReturnUsing(function ($delay) use (&$release) {
                $release[] = $delay;
            });
            $job = new SendNotifyEvent($payload);
            $job->setJob($queueJob);
            $job->handle(app(NotifyClient::class));
        }

        $this->assertSame([60, 300], $release, 'released twice with backoff; the third failure is abandoned, not released');
    }

    public function test_a_permanent_refusal_is_not_retried(): void
    {
        $this->fakeNotify([self::URL => Http::response('nope', 422)]);
        $queueJob = \Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('release')->never();
        $job = new SendNotifyEvent(['platform' => 'hr', 'event' => 'leave.requested']);
        $job->setJob($queueJob);

        $job->handle(app(NotifyClient::class));

        Http::assertSentCount(1);
    }

    public function test_the_request_uses_a_five_second_timeout(): void
    {
        $seen = null;
        $this->fakeNotify(function (Request $request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response([], 202);
        });

        app(NotifyClient::class)->deliver(['platform' => 'hr', 'event' => 'leave.requested']);

        $this->assertSame(5, (int) ($seen['timeout'] ?? 0));
        $this->assertSame(5, (int) ($seen['connect_timeout'] ?? 0));
    }

    public function test_outcomes_for_transient_and_permanent_responses(): void
    {
        $client = app(NotifyClient::class);
        $payload = ['platform' => 'hr', 'event' => 'leave.requested'];
        $this->fakeNotify(['*' => Http::sequence()->push('x', 503)->push('x', 429)->push('x', 422)->push([], 200)->pushFailedConnection()]);

        $this->assertSame(NotifyClient::RETRY, $client->deliver($payload));
        $this->assertSame(NotifyClient::RETRY, $client->deliver($payload));
        $this->assertSame(NotifyClient::GIVE_UP, $client->deliver($payload));
        $this->assertSame(NotifyClient::DONE, $client->deliver($payload));
        $this->assertSame(NotifyClient::RETRY, $client->deliver($payload));
    }

    public function test_a_retry_is_signed_afresh_with_a_new_timestamp(): void
    {
        $client = app(NotifyClient::class);
        $payload = ['platform' => 'hr', 'event' => 'leave.requested', 'idempotency_key' => 'k'];

        $client->deliver($payload);
        Carbon::setTestNow(now()->addMinutes(10));
        $client->deliver($payload);

        $stamps = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('X-InfoDot-Timestamp')[0])->all();
        $this->assertCount(2, array_unique($stamps));
    }

    // ------------------------------------------------------------- circuit breaker

    public function test_the_circuit_opens_after_five_consecutive_failures_and_closes_again(): void
    {
        $this->fakeNotify([self::URL => Http::response('boom', 500)]);
        $client = app(NotifyClient::class);
        $payload = ['platform' => 'hr', 'event' => 'leave.requested'];

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(NotifyClient::RETRY, $client->deliver($payload));
        }
        Http::assertSentCount(5);

        // Open: the next calls do not touch Notify at all, and ask for a later retry.
        $this->assertSame(NotifyClient::RETRY, $client->deliver($payload));
        $this->assertSame(NotifyClient::RETRY, $client->deliver($payload));
        Http::assertSentCount(5);

        // After 60 seconds one probe goes out; Notify is back, so the circuit closes.
        Carbon::setTestNow(now()->addSeconds(61));
        $this->fakeNotify([self::URL => Http::response([], 202)]);
        $this->assertSame(NotifyClient::DONE, $client->deliver($payload));
        $this->assertSame(NotifyClient::DONE, $client->deliver($payload));
        $this->assertCount(2, Http::recorded(), 'closed again: both calls went out');

        // Closing reset the count: four failures in a row do not reopen it.
        $this->fakeNotify([self::URL => Http::response('boom', 500)]);
        for ($i = 0; $i < 4; $i++) {
            $client->deliver($payload);
        }
        Http::assertSentCount(4);
        $this->fakeNotify([self::URL => Http::response([], 202)]);
        $this->assertSame(NotifyClient::DONE, $client->deliver($payload));
    }

    public function test_a_failed_probe_reopens_the_circuit_for_another_minute(): void
    {
        $this->fakeNotify([self::URL => Http::response('boom', 500)]);
        $client = app(NotifyClient::class);
        $payload = ['platform' => 'hr', 'event' => 'leave.requested'];
        for ($i = 0; $i < 5; $i++) {
            $client->deliver($payload);
        }

        Carbon::setTestNow(now()->addSeconds(61));
        $client->deliver($payload); // the probe, fails
        $client->deliver($payload); // open again: no call
        $client->deliver($payload);

        Http::assertSentCount(6);
    }

    public function test_a_permanent_refusal_does_not_count_towards_the_circuit(): void
    {
        $this->fakeNotify([self::URL => Http::response('no', 401)]);
        $client = app(NotifyClient::class);

        for ($i = 0; $i < 8; $i++) {
            $client->deliver(['platform' => 'hr', 'event' => 'leave.requested']);
        }

        Http::assertSentCount(8);
    }

    public function test_an_open_circuit_leaves_the_user_action_and_in_app_notification_untouched(): void
    {
        $this->fakeNotify([self::URL => Http::response('boom', 500)]);
        $f = $this->linked();
        for ($i = 0; $i < 5; $i++) {
            app(NotifyClient::class)->deliver(['platform' => 'hr', 'event' => 'leave.requested']);
        }

        $this->submit($f);

        $this->assertSame(1, LeaveRequest::withoutGlobalScopes()->count());
        $this->assertSame(1, $f->owner->notifications()->count());
        Http::assertSentCount(5);
    }

    // ------------------------------------------------------------- idempotency and privacy

    public function test_the_idempotency_key_is_stable_across_retries_and_double_dispatch(): void
    {
        $f = $this->linked();
        $leave = $this->pending($f);
        $client = app(NotifyClient::class);

        $first = $client->leaveRequestedPayload($leave->fresh(), $f->owner);
        Carbon::setTestNow(now()->addHours(3));
        $again = $client->leaveRequestedPayload($leave->fresh(), $f->owner);
        $other = $client->leaveRequestedPayload($leave->fresh(), $f->admin);

        $this->assertSame($first['idempotency_key'], $again['idempotency_key']);
        $this->assertNotSame($first['idempotency_key'], $other['idempotency_key'], 'a different approver is a different event');
        $this->assertMatchesRegularExpression('/^leave\.requested:\d+:[a-f0-9]{40}:2026-03-09$/', $first['idempotency_key']);

        $decided = $client->leaveDecidedPayload($leave->fresh()->forceFill(['status' => 'approved']), $f->admin);
        $this->assertSame($decided['idempotency_key'], $client->leaveDecidedPayload($leave->fresh()->forceFill(['status' => 'approved']), $f->admin)['idempotency_key']);
    }

    public function test_log_lines_contain_no_email_addresses_names_or_secret(): void
    {
        $logged = [];
        Log::listen(function ($event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        $f = $this->linked();

        $this->fakeNotify([self::URL => Http::response('boom', 500)]);
        $this->submit($f);
        $this->fakeNotify([self::URL => fn () => throw new ConnectionException('timeout')]);
        $leave = LeaveRequest::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($f->admin)->post(route('leave-requests.approve', $leave));
        $this->fakeNotify([self::URL => Http::response('no', 422)]);
        $this->submit($f, ['start_date' => '2026-04-13', 'end_date' => '2026-04-14']);
        $f->team->forceFill(['infodot_team_uuid' => null])->save();
        $this->submit($f, ['start_date' => '2026-05-11', 'end_date' => '2026-05-12']);
        config(['services.notify.secret' => '']);
        $this->submit($f, ['start_date' => '2026-06-08', 'end_date' => '2026-06-09']);

        $this->assertNotEmpty($logged);
        $all = implode("\n", $logged);
        foreach ([$f->owner->email, $f->admin->email, $f->staff->email, 'thandi.work.private', 'Thandi', 'Mokoena', self::REASON, self::SECRET] as $needle) {
            $this->assertStringNotContainsString($needle, $all);
        }
    }
}
