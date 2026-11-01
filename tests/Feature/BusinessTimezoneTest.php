<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\HrAuditLog;
use App\Models\HrGrant;
use App\Models\Shift;
use App\Models\ShiftSwap;
use App\Models\User;
use App\Services\Hr\ShiftSwaps;
use App\Support\Hr\LocalTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * Each business works on its own clock (per-business time zone). At one instant, a business in
 * Auckland is already on the next day while one in Johannesburg is not: "today", clock-ins and
 * swap expiry follow each business's clock, and nothing stored is converted.
 */
class BusinessTimezoneTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The suite pins HR_LOCAL_TIMEZONE to UTC; these tests use the production default.
        config(['hr.local_timezone' => 'Africa/Johannesburg']);
        // 11:30 UTC: 13:30 in Johannesburg (UTC+2), 00:30 on 2 November in Auckland (UTC+13 in summer).
        Carbon::setTestNow(Carbon::parse('2026-11-01 11:30:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function auckland(User $owner): void
    {
        $owner->currentTeam->forceFill(['timezone' => 'Pacific/Auckland'])->save();
    }

    public function test_hr_admins_set_the_zone_and_nobody_else_can(): void
    {
        $owner = $this->owner();
        $manager = $this->memberOf($owner, 'editor');
        $supervisor = $this->memberOf($owner, 'viewer');
        HrGrant::create(['team_id' => $owner->currentTeam->id, 'user_id' => $supervisor->id, 'capability' => HrGrant::SUPERVISOR, 'granted_by' => $owner->id]);

        $this->actingAs($owner)->get(route('schedule.index'))->assertSee('Africa/Johannesburg', false)->assertSee('Times are Africa/Johannesburg time; it is 13:30');

        $this->actingAs($manager)->put(route('schedule.timezone'), ['timezone' => 'Africa/Nairobi'])->assertForbidden();
        $this->actingAs($supervisor)->put(route('schedule.timezone'), ['timezone' => 'Africa/Nairobi'])->assertForbidden();
        $this->actingAs($owner)->put(route('schedule.timezone'), ['timezone' => 'Mars/Olympus_Mons'])->assertSessionHasErrors('timezone');

        $this->actingAs($owner)->put(route('schedule.timezone'), ['timezone' => 'Africa/Nairobi'])->assertRedirect()->assertSessionHas('status', fn ($s) => str_contains($s, '14:30'));
        $this->assertSame('Africa/Nairobi', $owner->currentTeam->fresh()->timezone);
        $log = HrAuditLog::withoutTeamScope(fn () => HrAuditLog::query()->where('action', 'business.timezone_changed')->sole());
        $this->assertSame(['from' => null, 'to' => 'Africa/Nairobi'], $log->changes['timezone']);

        $this->actingAs($owner)->put(route('schedule.timezone'), ['timezone' => ''])->assertRedirect();
        $this->assertNull($owner->currentTeam->fresh()->timezone);
    }

    public function test_each_business_has_its_own_today_and_clock_ins_follow_it(): void
    {
        $jhb = $this->owner();
        $akl = $this->owner();
        $this->auckland($akl);

        $this->actingAs($jhb);
        $this->assertSame('2026-11-01', LocalTime::today()->toDateString());
        $this->actingAs($akl);
        $akl->unsetRelation('currentTeam');
        $this->assertSame('2026-11-02', LocalTime::today()->toDateString());

        // A clock-in is stored as the business's own wall-clock time.
        $staff = $this->memberOf($akl, 'viewer');
        $this->employeeIn($akl->currentTeam, $staff);
        $this->actingAs($staff)->post(route('my-work.clock', 'in'))->assertRedirect()->assertSessionHasNoErrors();
        $record = AttendanceRecord::withoutTeamScope(fn () => AttendanceRecord::query()->sole());
        $this->assertSame('2026-11-02 00:30', $record->clock_in_at->format('Y-m-d H:i'));
    }

    public function test_work_with_nobody_signed_in_uses_for_team_and_restores_the_clock(): void
    {
        $akl = $this->owner();
        $this->auckland($akl);
        Auth::logout();

        $this->assertSame('Africa/Johannesburg', LocalTime::zone());
        $inside = LocalTime::forTeam($akl->currentTeam, fn () => [LocalTime::zone(), LocalTime::today()->toDateString()]);
        $this->assertSame(['Pacific/Auckland', '2026-11-02'], $inside);
        $this->assertSame('Africa/Johannesburg', LocalTime::zone(), 'the override is not sticky');
    }

    public function test_swap_expiry_follows_each_business_clock(): void
    {
        $results = [];
        foreach (['Africa/Johannesburg', 'Pacific/Auckland'] as $zone) {
            $owner = $this->owner();
            $owner->currentTeam->forceFill(['timezone' => $zone])->save();
            $staff = $this->memberOf($owner, 'viewer');
            $employee = $this->employeeIn($owner->currentTeam, $staff);

            // A shift typed as midnight on 2 November: started in Auckland (00:30), hours away in Johannesburg.
            $shift = new Shift(['starts_at' => '2026-11-02 00:00:00', 'ends_at' => '2026-11-02 08:00:00', 'break_minutes' => 0]);
            $shift->forceFill(['team_id' => $owner->currentTeam->id])->save();
            $shift->employees()->attach($employee->id, ['team_id' => $shift->team_id]);
            $swap = new ShiftSwap;
            $swap->forceFill(['team_id' => $shift->team_id, 'shift_id' => $shift->id, 'from_employee_id' => $employee->id, 'status' => 'offered'])->save();

            $this->actingAs($staff);
            $staff->unsetRelation('currentTeam');
            app(ShiftSwaps::class)->expireStale($owner->currentTeam->id);
            $results[$zone] = ShiftSwap::withoutTeamScope(fn () => ShiftSwap::query()->findOrFail($swap->id))->status;
        }

        $this->assertSame(['Africa/Johannesburg' => 'offered', 'Pacific/Auckland' => 'expired'], $results);
    }
}
