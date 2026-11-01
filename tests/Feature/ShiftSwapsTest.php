<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\HrGrant;
use App\Models\Shift;
use App\Models\ShiftSwap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * Shift swaps (brief §8): offered by the person, accepted by a colleague, approved by a
 * supervisor; the schedule changes only on approval and only within the scheduling rules.
 */
class ShiftSwapsTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    /** @return object{owner: User, supervisor: User, manager: User, a: User, b: User, c: User, ea: Employee, eb: Employee, ec: Employee} */
    private function team(): object
    {
        $owner = $this->owner();
        $supervisor = $this->memberOf($owner, 'viewer');
        HrGrant::create(['team_id' => $owner->currentTeam->id, 'user_id' => $supervisor->id, 'capability' => HrGrant::SUPERVISOR, 'granted_by' => $owner->id]);
        $manager = $this->memberOf($owner, 'editor');
        [$a, $b, $c] = [$this->memberOf($owner, 'viewer'), $this->memberOf($owner, 'viewer'), $this->memberOf($owner, 'viewer')];
        $ea = $this->employeeIn($owner->currentTeam, $a, ['first_name' => 'Thandi']);
        $eb = $this->employeeIn($owner->currentTeam, $b, ['first_name' => 'Sipho']);
        $ec = $this->employeeIn($owner->currentTeam, $c, ['first_name' => 'Lerato']);

        return (object) compact('owner', 'supervisor', 'manager', 'a', 'b', 'c', 'ea', 'eb', 'ec');
    }

    private function shift(object $t, Carbon $start, Employee ...$people): Shift
    {
        $shift = new Shift(['starts_at' => $start, 'ends_at' => $start->copy()->addHours(8), 'break_minutes' => 30, 'title' => 'Till']);
        $shift->forceFill(['team_id' => $t->owner->currentTeam->id])->save();
        foreach ($people as $p) {
            $shift->employees()->attach($p->id, ['team_id' => $shift->team_id]);
        }

        return $shift;
    }

    private function latestSwap(): ShiftSwap
    {
        return ShiftSwap::withoutTeamScope(fn () => ShiftSwap::query()->latest('id')->firstOrFail());
    }

    public function test_a_shift_handed_to_a_colleague_moves_only_after_approval(): void
    {
        $t = $this->team();
        $shift = $this->shift($t, now()->addDays(3)->setTime(8, 0), $t->ea);

        $this->actingAs($t->a)->post(route('my-work.swaps.offer', $shift), ['with' => 'e:'.$t->eb->id, 'note' => 'Family wedding'])->assertRedirect()->assertSessionHasNoErrors();
        $swap = $this->latestSwap();
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $t->b->id]);
        $this->actingAs($t->b)->get(route('my-work.index'))->assertSee('offers you a shift')->assertSee('Family wedding');

        $this->actingAs($t->c)->post(route('my-work.swaps.accept', $swap))->assertSessionHasErrors('swap');
        $this->actingAs($t->b)->post(route('my-work.swaps.accept', $swap))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('accepted', $swap->fresh()->status);
        $this->assertSame([$t->ea->id], $shift->employees()->pluck('employees.id')->all(), 'nothing changes before approval');
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $t->supervisor->id]);

        $this->actingAs($t->manager)->get(route('schedule.swaps'))->assertForbidden();
        $this->actingAs($t->supervisor)->get(route('schedule.swaps'))->assertOk()->assertSee('hands over to');
        $this->actingAs($t->supervisor)->post(route('schedule.swaps.decide', [$swap, 'approve']))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('approved', $swap->fresh()->status);
        $this->assertSame([$t->eb->id], $shift->employees()->pluck('employees.id')->all());
    }

    public function test_an_exchange_moves_both_people(): void
    {
        $t = $this->team();
        $mine = $this->shift($t, now()->addDays(2)->setTime(8, 0), $t->ea);
        $theirs = $this->shift($t, now()->addDays(4)->setTime(14, 0), $t->eb);

        $this->actingAs($t->a)->post(route('my-work.swaps.offer', $mine), ['with' => 's:'.$theirs->id.':'.$t->eb->id])->assertSessionHasNoErrors();
        $swap = $this->latestSwap();
        $this->actingAs($t->b)->post(route('my-work.swaps.accept', $swap))->assertSessionHasNoErrors();
        $this->actingAs($t->owner)->post(route('schedule.swaps.decide', [$swap, 'approve']))->assertSessionHasNoErrors();

        $this->assertSame([$t->eb->id], $mine->employees()->pluck('employees.id')->all());
        $this->assertSame([$t->ea->id], $theirs->employees()->pluck('employees.id')->all());
    }

    public function test_the_scheduling_rules_hold_at_every_step(): void
    {
        $t = $this->team();
        $mine = $this->shift($t, now()->addDays(3)->setTime(8, 0), $t->ea);
        $sipho = $this->shift($t, now()->addDays(5)->setTime(8, 0), $t->eb);

        // Only your own shift, and only once.
        $this->actingAs($t->a)->post(route('my-work.swaps.offer', $sipho), ['with' => ''])->assertSessionHasErrors('shift_id');
        $this->actingAs($t->a)->post(route('my-work.swaps.offer', $mine), ['with' => 'e:'.$t->eb->id])->assertSessionHasNoErrors();
        $this->actingAs($t->a)->post(route('my-work.swaps.offer', $mine), ['with' => 'e:'.$t->ec->id])->assertSessionHasErrors('shift_id');
        $swap = $this->latestSwap();

        // Lerato cannot take it if she is already working then.
        $this->actingAs($t->a)->post(route('my-work.swaps.cancel', $swap))->assertRedirect();
        $this->shift($t, now()->addDays(3)->setTime(10, 0), $t->ec);
        $this->actingAs($t->a)->post(route('my-work.swaps.offer', $mine), ['with' => 'e:'.$t->ec->id])->assertSessionHasNoErrors();
        $this->actingAs($t->c)->post(route('my-work.swaps.accept', $this->latestSwap()))->assertSessionHasErrors('swap');

        // Leave approved after acceptance blocks the approval and leaves the schedule alone.
        $this->actingAs($t->a)->post(route('my-work.swaps.cancel', $this->latestSwap()))->assertRedirect();
        $this->actingAs($t->a)->post(route('my-work.swaps.offer', $mine), ['with' => 'e:'.$t->eb->id])->assertSessionHasNoErrors();
        $swap = $this->latestSwap();
        $this->actingAs($t->b)->post(route('my-work.swaps.accept', $swap))->assertSessionHasNoErrors();
        $this->leaveFor($t->eb, now()->addDays(3)->toDateString(), now()->addDays(3)->toDateString(), ['status' => 'approved']);
        $this->actingAs($t->owner)->post(route('schedule.swaps.decide', [$swap, 'approve']))->assertSessionHasErrors('swap');
        $this->assertSame('accepted', $swap->fresh()->status);
        $this->assertSame([$t->ea->id], $mine->employees()->pluck('employees.id')->all());
    }

    public function test_open_offers_declines_and_expiry(): void
    {
        $t = $this->team();
        $mine = $this->shift($t, now()->addDays(3)->setTime(8, 0), $t->ea);

        $this->actingAs($t->a)->post(route('my-work.swaps.offer', $mine), ['with' => ''])->assertSessionHasNoErrors();
        $open = $this->latestSwap();
        $this->actingAs($t->c)->get(route('my-work.index'))->assertSee('Up for grabs');
        $this->actingAs($t->a)->post(route('my-work.swaps.accept', $open))->assertSessionHasErrors('swap');
        $this->actingAs($t->c)->post(route('my-work.swaps.accept', $open))->assertSessionHasNoErrors();
        $this->assertSame($t->ec->id, $open->fresh()->to_employee_id);
        $this->actingAs($t->owner)->post(route('schedule.swaps.decide', [$open, 'decline']), ['note' => 'We need Thandi on the till.'])->assertSessionHasNoErrors();
        $this->assertSame('declined', $open->fresh()->status);
        $this->assertSame([$t->ea->id], $mine->employees()->pluck('employees.id')->all());

        $this->actingAs($t->a)->post(route('my-work.swaps.offer', $mine), ['with' => 'e:'.$t->eb->id])->assertSessionHasNoErrors();
        $named = $this->latestSwap();
        $this->actingAs($t->b)->post(route('my-work.swaps.decline', $named))->assertRedirect();
        $this->assertSame('declined', $named->fresh()->status);

        // Once the shift has started, an unsettled offer expires.
        $this->actingAs($t->a)->post(route('my-work.swaps.offer', $mine), ['with' => ''])->assertSessionHasNoErrors();
        $late = $this->latestSwap();
        $mine->forceFill(['starts_at' => now()->subHour(), 'ends_at' => now()->addHours(7)])->save();
        $this->actingAs($t->c)->post(route('my-work.swaps.accept', $late))->assertSessionHasErrors('swap');
        $this->assertSame('expired', $late->fresh()->status);
    }

    public function test_another_business_cannot_be_reached(): void
    {
        $t = $this->team();
        $mine = $this->shift($t, now()->addDays(3)->setTime(8, 0), $t->ea);
        $stranger = $this->owner();
        $outsider = $this->employeeIn($stranger->currentTeam, null);

        $this->actingAs($t->a)->post(route('my-work.swaps.offer', $mine), ['with' => 'e:'.$outsider->id])->assertNotFound();
        $this->actingAs($t->a)->post(route('my-work.swaps.offer', $mine), ['with' => ''])->assertSessionHasNoErrors();
        $this->actingAs($stranger)->post(route('schedule.swaps.decide', [$this->latestSwap(), 'approve']))->assertNotFound();
    }
}
