<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\HrAuditLog;
use App\Models\HrGrant;
use App\Models\Position;
use App\Models\TrainingRecord;
use App\Models\User;
use App\Services\Hr\TrainingBudgets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

/**
 * Training costs, who pays, budgets with booked and spent apart, and the training report CSV.
 * Only training the business pays counts against a budget; nothing uncosted is counted as zero.
 */
class TrainingBudgetsTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    /** @return object{owner: User, supervisor: User, member: User, shop: Employee, office: Employee} */
    private function team(): object
    {
        $owner = $this->owner();
        $supervisor = $this->memberOf($owner, 'viewer');
        HrGrant::create(['team_id' => $owner->currentTeam->id, 'user_id' => $supervisor->id, 'capability' => HrGrant::SUPERVISOR, 'granted_by' => $owner->id]);
        $member = $this->memberOf($owner, 'viewer');
        $shopPos = Position::factory()->create(['team_id' => $owner->currentTeam->id, 'title' => 'Cashier', 'department' => 'Shop floor']);
        $officePos = Position::factory()->create(['team_id' => $owner->currentTeam->id, 'title' => 'Bookkeeper', 'department' => 'Office']);
        $shop = $this->employeeIn($owner->currentTeam, null, ['first_name' => 'Thandi', 'position_id' => $shopPos->id]);
        $office = $this->employeeIn($owner->currentTeam, null, ['first_name' => 'Sipho', 'position_id' => $officePos->id]);

        return (object) compact('owner', 'supervisor', 'member', 'shop', 'office');
    }

    private function book(User $by, Employee $e, string $title, array $extra = []): TrainingRecord
    {
        $this->actingAs($by)->post(route('training.store'), $extra + ['employee_id' => $e->id, 'title' => $title, 'kind' => 'external', 'starts_on' => now()->addWeek()->toDateString()])
            ->assertRedirect()->assertSessionHasNoErrors();

        return TrainingRecord::withoutTeamScope(fn () => TrainingRecord::query()->latest('id')->firstOrFail());
    }

    public function test_bookings_carry_a_cost_and_who_pays(): void
    {
        $t = $this->team();
        $paid = $this->book($t->supervisor, $t->shop, 'Cash handling', ['cost' => '4500', 'currency' => 'zar']);
        $this->assertSame(['4500.00', 'ZAR', 'business'], [$paid->cost, $paid->currency, $paid->paid_by]);

        $this->actingAs($t->supervisor)->post(route('training.store'), ['employee_id' => $t->shop->id, 'title' => 'Forklift', 'kind' => 'external', 'cost' => '3000', 'paid_by' => 'sponsor'])->assertSessionHasErrors('sponsor');
        $grant = $this->book($t->supervisor, $t->shop, 'Forklift', ['cost' => '3000', 'paid_by' => 'sponsor', 'sponsor' => 'Services SETA']);
        $this->assertSame('ZAR 3 000.00, paid by a sponsor or funder (Services SETA)', $grant->costLabel());

        $plain = $this->book($t->supervisor, $t->office, 'Excel basics');
        $this->assertNull($plain->cost);
        $this->assertSame('business', $plain->paid_by);

        // The actual cost at completion replaces the booked one.
        $this->actingAs($t->supervisor)->post(route('training.complete', $paid), ['completed_on' => now()->toDateString(), 'hours' => '8', 'cost' => '4800'])->assertRedirect();
        $this->assertSame('4800.00', $paid->fresh()->cost);
        $this->assertSame('business', $paid->fresh()->paid_by);
    }

    public function test_budgets_count_only_what_the_business_pays_booked_and_spent_apart(): void
    {
        $t = $this->team();
        $this->actingAs($t->supervisor)->post(route('training.budgets.store'), ['starts_on' => now()->startOfYear()->toDateString(), 'ends_on' => now()->endOfYear()->toDateString(), 'amount' => '10000', 'currency' => 'ZAR'])->assertForbidden();
        $this->actingAs($t->owner)->post(route('training.budgets.store'), ['starts_on' => now()->startOfYear()->toDateString(), 'ends_on' => now()->endOfYear()->toDateString(), 'amount' => '10000', 'currency' => 'ZAR'])->assertRedirect();
        $this->actingAs($t->owner)->post(route('training.budgets.store'), ['department' => 'Office', 'starts_on' => now()->startOfYear()->toDateString(), 'ends_on' => now()->endOfYear()->toDateString(), 'amount' => '2000', 'currency' => 'ZAR'])->assertRedirect();

        $this->book($t->owner, $t->shop, 'Cash handling', ['cost' => '4500']);                       // booked, business
        $done = $this->book($t->owner, $t->office, 'Payroll course', ['cost' => '3000']);
        $this->actingAs($t->owner)->post(route('training.complete', $done), ['completed_on' => now()->toDateString()])->assertRedirect(); // spent, Office
        $this->book($t->owner, $t->shop, 'Own course', ['cost' => '2000', 'paid_by' => 'employee']);  // the person pays: not counted
        $this->book($t->owner, $t->shop, 'First aid');                                                // no cost: counted as uncosted

        $this->actingAs($t->owner);
        $rows = app(TrainingBudgets::class)->overview()->keyBy(fn ($b) => $b['budget']->department ?? 'all');
        $this->assertSame([4500.0, 3000.0, 2500.0, 1], [$rows['all']['booked'], $rows['all']['spent'], $rows['all']['left'], $rows['all']['uncosted']]);
        $this->assertSame([0.0, 3000.0, -1000.0], [$rows['Office']['booked'], $rows['Office']['spent'], $rows['Office']['left']]);

        $this->actingAs($t->owner)->get(route('training.index'))->assertSee('Over by')->assertSee('no cost recorded');
        $this->actingAs($t->supervisor)->get(route('training.index'))->assertOk()->assertDontSee('Set a budget');
    }

    public function test_the_training_report_lists_completed_training_for_the_period(): void
    {
        $t = $this->team();
        $old = $this->book($t->owner, $t->shop, 'Last year course', ['cost' => '1000']);
        $this->actingAs($t->owner)->post(route('training.complete', $old), ['completed_on' => now()->subYear()->toDateString()])->assertRedirect();
        $new = $this->book($t->owner, $t->office, 'Payroll course', ['cost' => '3000', 'paid_by' => 'sponsor', 'sponsor' => 'FASSET']);
        $this->actingAs($t->owner)->post(route('training.complete', $new), ['completed_on' => now()->toDateString(), 'hours' => '16'])->assertRedirect();
        $this->book($t->owner, $t->shop, 'Still booked');

        $this->actingAs($t->member)->get(route('training.report'))->assertForbidden();
        $body = $this->actingAs($t->supervisor)->get(route('training.report', ['from' => now()->startOfYear()->toDateString(), 'to' => now()->toDateString()]))->assertOk()->streamedContent();

        $lines = array_values(array_filter(explode("\r\n", substr($body, 3))));
        $this->assertCount(2, $lines, 'header and the one course completed this period');
        $row = str_getcsv($lines[1], ',', '"', '');
        $this->assertSame(['Sipho', 'Bookkeeper', 'Office', 'Payroll course'], [explode(' ', $row[0])[0], $row[1], $row[2], $row[3]]);
        $this->assertSame(['16', '3000', 'ZAR', 'A sponsor or funder', 'FASSET'], [$row[8], $row[9], $row[10], $row[11], $row[12]]);
        $this->assertTrue(HrAuditLog::withoutTeamScope(fn () => HrAuditLog::query()->where('action', 'training.report_exported')->exists()));
    }
}
