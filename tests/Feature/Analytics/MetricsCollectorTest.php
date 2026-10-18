<?php

namespace Tests\Feature\Analytics;

use App\Models\Employee;
use App\Models\Team;
use App\Models\User;
use App\Services\Analytics\MetricsCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * The aggregate numbers one company publishes to Dot.Analytics. Pure queries, always scoped to the company
 * explicitly (the team scope fails closed with nobody signed in), in UTC, never counting deleted rows.
 */
class MetricsCollectorTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 12:00:00'); // a Thursday, UTC
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function company(): Team
    {
        $owner = User::factory()->create();

        return Team::forceCreate(['user_id' => $owner->id, 'name' => 'Company '.uniqid(), 'personal_team' => false, 'infodot_team_uuid' => (string) Str::uuid()]);
    }

    /** @param array<string, mixed> $attributes */
    private function employee(Team $team, array $attributes = []): Employee
    {
        return $this->employeeIn($team, null, array_merge(['email' => fake()->unique()->safeEmail()], $attributes));
    }

    /** @return array<string, int> */
    private function collect(Team $team): array
    {
        return app(MetricsCollector::class)->collect($team);
    }

    public function test_it_reports_every_key_analytics_defines_for_hr(): void
    {
        $this->assertSame(['headcount', 'on_leave_today', 'pending_leave_requests'], array_keys($this->collect($this->company())));
    }

    public function test_a_company_with_no_people_reports_real_zeros_as_whole_numbers(): void
    {
        $this->assertSame(['headcount' => 0, 'on_leave_today' => 0, 'pending_leave_requests' => 0], $this->collect($this->company()));
    }

    // ------------------------------------------------------------- headcount

    public function test_headcount_is_everyone_not_terminated_including_people_on_leave(): void
    {
        $team = $this->company();
        $this->employee($team);
        $this->employee($team, ['employment_type' => 'contractor']);
        $away = $this->employee($team);
        $this->leaveFor($away, '2026-11-04', '2026-11-06', ['status' => 'approved']);
        $this->employee($team, ['status' => 'terminated']);

        $this->assertSame(3, $this->collect($team)['headcount']);
    }

    public function test_headcount_leaves_out_soft_deleted_employees(): void
    {
        $team = $this->company();
        $this->employee($team);
        $this->employee($team)->delete();

        $this->assertSame(1, $this->collect($team)['headcount']);
    }

    // ------------------------------------------------------------- on_leave_today

    public function test_on_leave_today_counts_approved_leave_covering_today_inclusive_of_both_ends(): void
    {
        $team = $this->company();
        $this->leaveFor($this->employee($team), '2026-11-05', '2026-11-05', ['status' => 'approved']);   // just today
        $this->leaveFor($this->employee($team), '2026-11-03', '2026-11-05', ['status' => 'approved']);   // ends today
        $this->leaveFor($this->employee($team), '2026-11-05', '2026-11-09', ['status' => 'approved']);   // starts today
        $this->leaveFor($this->employee($team), '2026-11-01', '2026-11-30', ['status' => 'approved']);   // spans today
        $this->leaveFor($this->employee($team), '2026-11-02', '2026-11-04', ['status' => 'approved']);   // ended yesterday
        $this->leaveFor($this->employee($team), '2026-11-06', '2026-11-10', ['status' => 'approved']);   // starts tomorrow

        $this->assertSame(4, $this->collect($team)['on_leave_today']);
    }

    public function test_on_leave_today_needs_approved_leave_not_pending_denied_or_cancelled(): void
    {
        $team = $this->company();
        foreach (['pending', 'denied', 'cancelled'] as $status) {
            $this->leaveFor($this->employee($team), '2026-11-04', '2026-11-06', ['status' => $status]);
        }
        $this->leaveFor($this->employee($team), '2026-11-04', '2026-11-06', ['status' => 'approved']);

        $this->assertSame(1, $this->collect($team)['on_leave_today']);
    }

    public function test_on_leave_today_counts_a_person_once_however_many_approved_requests_cover_today(): void
    {
        $team = $this->company();
        $person = $this->employee($team);
        $this->leaveFor($person, '2026-11-04', '2026-11-06', ['status' => 'approved']);
        $this->leaveFor($person, '2026-11-05', '2026-11-05', ['status' => 'approved', 'half_day' => 'am']);

        $this->assertSame(1, $this->collect($team)['on_leave_today']);
    }

    public function test_on_leave_today_leaves_out_deleted_leave_and_deleted_or_terminated_employees(): void
    {
        $team = $this->company();
        $this->leaveFor($this->employee($team), '2026-11-04', '2026-11-06', ['status' => 'approved'])->delete();
        $gone = $this->employee($team);
        $this->leaveFor($gone, '2026-11-04', '2026-11-06', ['status' => 'approved']);
        $gone->delete();
        $left = $this->employee($team, ['status' => 'terminated']);
        $this->leaveFor($left, '2026-11-04', '2026-11-06', ['status' => 'approved']);
        $this->leaveFor($this->employee($team), '2026-11-04', '2026-11-06', ['status' => 'approved']);

        $this->assertSame(1, $this->collect($team)['on_leave_today']);
    }

    public function test_today_is_the_utc_day_even_when_the_server_clock_is_elsewhere(): void
    {
        $team = $this->company();
        $this->leaveFor($this->employee($team), '2026-11-05', '2026-11-06', ['status' => 'approved']); // starts "today" in Johannesburg only
        $this->leaveFor($this->employee($team), '2026-11-03', '2026-11-04', ['status' => 'approved']); // ends "today" in UTC
        $this->leaveFor($this->employee($team), '2026-11-04', '2026-11-04', ['status' => 'approved']); // just "today" in UTC

        // 01:30 on 5 November in Johannesburg is still 23:30 on 4 November in UTC.
        $johannesburg = Carbon::parse('2026-11-05 01:30:00', 'Africa/Johannesburg');

        $this->assertSame(2, app(MetricsCollector::class)->collect($team, $johannesburg)['on_leave_today'], 'the UTC day (4 Nov) is what counts, not the Johannesburg one');
        $this->assertSame(1, $this->collect($team)['on_leave_today'], 'at noon UTC on the 5th only the 5th-6th leave covers today');
    }

    // ------------------------------------------------------------- pending_leave_requests

    public function test_pending_counts_only_requests_still_awaiting_a_decision(): void
    {
        $team = $this->company();
        $person = $this->employee($team);
        $this->leaveFor($person, '2026-12-01', '2026-12-02', ['status' => 'pending']);
        $this->leaveFor($person, '2026-12-08', '2026-12-09', ['status' => 'pending']);
        foreach (['approved', 'denied', 'cancelled'] as $i => $status) {
            $this->leaveFor($person, '2027-01-0'.($i + 1), '2027-01-0'.($i + 1), ['status' => $status]);
        }

        $this->assertSame(2, $this->collect($team)['pending_leave_requests']);
    }

    public function test_pending_leaves_out_deleted_requests_and_requests_of_deleted_employees(): void
    {
        $team = $this->company();
        $this->leaveFor($this->employee($team), '2026-12-01', '2026-12-02', ['status' => 'pending'])->delete();
        $gone = $this->employee($team);
        $this->leaveFor($gone, '2026-12-01', '2026-12-02', ['status' => 'pending']);
        $gone->delete();
        $this->leaveFor($this->employee($team), '2026-12-01', '2026-12-02', ['status' => 'pending']);

        $this->assertSame(1, $this->collect($team)['pending_leave_requests']);
    }

    // ------------------------------------------------------------- isolation

    public function test_company_a_numbers_never_include_company_b_rows(): void
    {
        $a = $this->company();
        $b = $this->company();
        $this->leaveFor($this->employee($a), '2026-11-04', '2026-11-06', ['status' => 'approved']);
        for ($i = 0; $i < 4; $i++) {
            $person = $this->employee($b);
            $this->leaveFor($person, '2026-11-04', '2026-11-06', ['status' => 'approved']);
            $this->leaveFor($person, '2026-12-01', '2026-12-01', ['status' => 'pending']);
        }

        $this->assertSame(['headcount' => 1, 'on_leave_today' => 1, 'pending_leave_requests' => 0], $this->collect($a));
        $this->assertSame(['headcount' => 4, 'on_leave_today' => 4, 'pending_leave_requests' => 4], $this->collect($b));
    }

    public function test_the_numbers_are_the_same_whoever_is_signed_in_or_nobody(): void
    {
        $a = $this->company();
        $b = $this->company();
        $this->employee($a);
        $this->employee($b);
        $this->employee($b);
        $b->owner->forceFill(['current_team_id' => $b->id])->save();

        $anonymous = $this->collect($a);
        $this->actingAs($b->owner);

        $this->assertSame(['headcount' => 1, 'on_leave_today' => 0, 'pending_leave_requests' => 0], $anonymous, 'the team scope fails closed with nobody signed in; the collector must not rely on it');
        $this->assertSame($anonymous, $this->collect($a));
        $this->assertSame(2, $this->collect($b)['headcount']);
    }

    public function test_it_leaves_the_team_scope_switched_on_afterwards(): void
    {
        $team = $this->company();
        $this->employee($team);

        $this->collect($team);

        $this->assertSame(0, Employee::query()->count(), 'nobody signed in: the scope still fails closed after the collector ran');
    }
}
