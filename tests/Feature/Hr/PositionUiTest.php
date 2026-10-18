<?php

namespace Tests\Feature\Hr;

use App\Models\Employee;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

class PositionUiTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    public function test_admin_creates_edits_and_deletes_a_position(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('positions.store'), [
            'title' => 'Shift Supervisor', 'department' => 'Operations', 'description' => 'Runs the floor',
        ])->assertRedirect(route('positions.index'));

        $position = Position::firstOrFail();
        $this->assertSame($owner->currentTeam->id, $position->team_id);

        $this->actingAs($owner)->get(route('positions.index'))->assertSee('Shift Supervisor');

        $this->actingAs($owner)->put(route('positions.update', $position), [
            'title' => 'Senior Shift Supervisor', 'department' => 'Operations',
        ])->assertRedirect(route('positions.index'));
        $this->assertSame('Senior Shift Supervisor', $position->fresh()->title);

        $this->actingAs($owner)->delete(route('positions.destroy', $position))->assertRedirect(route('positions.index'));
        $this->assertDatabaseMissing('positions', ['id' => $position->id]);
    }

    public function test_title_is_required(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('positions.store'), ['title' => ''])->assertSessionHasErrors('title');
    }

    public function test_another_teams_position_is_not_found(): void
    {
        $owner = $this->owner();
        $foreign = Position::factory()->create(['team_id' => $this->owner()->currentTeam->id]);

        $this->actingAs($owner)->get(route('positions.edit', $foreign))->assertNotFound();
        $this->actingAs($owner)->delete(route('positions.destroy', $foreign))->assertNotFound();
    }

    public function test_editor_cannot_create_update_or_delete(): void
    {
        $owner = $this->owner();
        $editor = $this->memberOf($owner, 'editor');
        $position = Position::factory()->create(['team_id' => $owner->currentTeam->id, 'title' => 'Keep']);

        $this->actingAs($editor)->post(route('positions.store'), ['title' => 'Nope'])->assertForbidden();
        $this->actingAs($editor)->put(route('positions.update', $position), ['title' => 'Changed'])->assertForbidden();
        $this->actingAs($editor)->delete(route('positions.destroy', $position))->assertForbidden();

        $this->assertSame('Keep', $position->fresh()->title);
    }

    public function test_admin_assigns_and_unassigns_employees_from_a_position(): void
    {
        $owner = $this->owner();
        $position = Position::factory()->create(['team_id' => $owner->currentTeam->id]);
        $a = $this->employeeIn($owner->currentTeam, null, ['email' => 'a@example.test']);
        $b = $this->employeeIn($owner->currentTeam, null, ['email' => 'b@example.test', 'position_id' => $position->id]);

        $this->actingAs($owner)->put(route('positions.update', $position), [
            'title' => $position->title, 'employee_ids' => [$a->id],
        ])->assertRedirect();

        $this->assertSame($position->id, $a->fresh()->position_id);
        $this->assertNull($b->fresh()->position_id, 'unticked employees are unassigned');
    }

    public function test_assignment_ignores_employees_from_another_team(): void
    {
        $owner = $this->owner();
        $position = Position::factory()->create(['team_id' => $owner->currentTeam->id]);
        $foreign = $this->employeeIn($this->owner()->currentTeam);

        $this->actingAs($owner)->put(route('positions.update', $position), [
            'title' => $position->title, 'employee_ids' => [$foreign->id],
        ])->assertSessionHasErrors('employee_ids.0');

        $this->assertNull(Employee::withoutGlobalScopes()->find($foreign->id)->position_id);
    }

    public function test_assignment_is_audited_by_field_name(): void
    {
        $owner = $this->owner();
        $position = Position::factory()->create(['team_id' => $owner->currentTeam->id]);
        $employee = $this->employeeIn($owner->currentTeam);

        $this->actingAs($owner)->put(route('positions.update', $position), [
            'title' => $position->title, 'employee_ids' => [$employee->id],
        ]);

        $entry = DB::table('hr_audit_log')->where('action', 'employee.updated')->first();
        $this->assertNotNull($entry);
        $this->assertSame(['position_id'], json_decode($entry->fields, true));
    }

    public function test_the_index_shows_headcount_per_position_without_naming_people_to_editors(): void
    {
        $owner = $this->owner();
        $editor = $this->memberOf($owner, 'editor');
        $position = Position::factory()->create(['team_id' => $owner->currentTeam->id, 'title' => 'Dispatcher']);
        $this->employeeIn($owner->currentTeam, null, ['first_name' => 'Roster', 'last_name' => 'Person', 'position_id' => $position->id]);

        $this->actingAs($editor)->get(route('positions.index'))->assertSee('Dispatcher')->assertDontSee('Roster');
        $this->actingAs($owner)->get(route('positions.edit', $position))->assertSee('Roster');
    }

    public function test_the_employee_form_lists_the_teams_positions(): void
    {
        $owner = $this->owner();
        Position::factory()->create(['team_id' => $owner->currentTeam->id, 'title' => 'Welder']);

        $this->actingAs($owner)->get(route('employees.create'))->assertSee('Welder');
    }
}
