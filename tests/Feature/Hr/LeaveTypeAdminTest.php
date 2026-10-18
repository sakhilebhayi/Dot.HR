<?php

namespace Tests\Feature\Hr;

use App\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

class LeaveTypeAdminTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    public function test_admin_edits_the_entitlement_and_rules_of_a_type(): void
    {
        $owner = $this->owner();
        $annual = $this->leaveType($owner->currentTeam);

        $this->actingAs($owner)->put(route('leave-types.update', $annual), [
            'name' => 'Annual leave', 'annual_days' => 18, 'carry_over_days' => 4, 'is_paid' => 1, 'enforce_balance' => 1,
        ])->assertRedirect(route('leave-types.index'));

        $annual->refresh();
        $this->assertEquals(18, $annual->annual_days);
        $this->assertEquals(4, $annual->carry_over_days);
        $this->assertTrue($annual->enforce_balance);
    }

    public function test_admin_creates_a_custom_type_and_it_is_not_confused_with_defaults(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('leave-types.store'), [
            'name' => 'Study leave', 'annual_days' => 5, 'carry_over_days' => 0, 'is_paid' => 1,
        ])->assertRedirect(route('leave-types.index'));

        $type = LeaveType::where('name', 'Study leave')->firstOrFail();
        $this->assertSame('study-leave', $type->slug);
        $this->assertSame($owner->currentTeam->id, $type->team_id);
    }

    public function test_an_empty_annual_days_means_untracked(): void
    {
        $owner = $this->owner();
        $sick = $this->leaveType($owner->currentTeam, 'sick');

        $this->actingAs($owner)->put(route('leave-types.update', $sick), [
            'name' => 'Sick leave', 'annual_days' => '', 'carry_over_days' => 0,
        ])->assertRedirect();

        $this->assertNull($sick->fresh()->annual_days);
    }

    public function test_a_type_in_use_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $owner = $this->owner();
        $employee = $this->employeeIn($owner->currentTeam);
        $annual = $this->leaveType($owner->currentTeam);
        $other = $this->leaveType($owner->currentTeam, 'other');
        $this->leaveFor($employee, '2026-03-09', '2026-03-10');

        $this->actingAs($owner)->delete(route('leave-types.destroy', $annual))->assertSessionHasErrors('type');
        $this->assertDatabaseHas('leave_types', ['id' => $annual->id]);

        $this->actingAs($owner)->delete(route('leave-types.destroy', $other))->assertRedirect(route('leave-types.index'));
        $this->assertDatabaseMissing('leave_types', ['id' => $other->id]);
    }

    public function test_non_admins_cannot_manage_types_but_members_can_read_them_on_the_request_form(): void
    {
        $owner = $this->owner();
        $editor = $this->memberOf($owner, 'editor');
        $annual = $this->leaveType($owner->currentTeam);

        $this->actingAs($editor)->get(route('leave-types.index'))->assertForbidden();
        $this->actingAs($editor)->put(route('leave-types.update', $annual), ['name' => 'X'])->assertForbidden();
        $this->actingAs($editor)->post(route('leave-types.store'), ['name' => 'X'])->assertForbidden();
    }

    public function test_another_teams_type_is_not_found(): void
    {
        $owner = $this->owner();
        $foreign = $this->leaveType($this->owner()->currentTeam);

        $this->actingAs($owner)->put(route('leave-types.update', $foreign), ['name' => 'Hijack'])->assertNotFound();
    }
}
