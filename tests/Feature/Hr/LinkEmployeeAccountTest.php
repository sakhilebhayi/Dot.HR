<?php

namespace Tests\Feature\Hr;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

class LinkEmployeeAccountTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    public function test_admin_can_link_an_employee_to_a_team_member(): void
    {
        $owner = $this->owner();
        $member = $this->memberOf($owner, 'viewer');
        $employee = $this->employeeIn($owner->currentTeam, null, ['email' => 'nobody@example.test']);

        $this->actingAs($owner)
            ->post(route('employees.link', $employee), ['user_id' => $member->id])
            ->assertRedirect(route('employees.show', $employee));

        $this->assertSame($member->id, $employee->fresh()->user_id);
    }

    public function test_admin_can_unlink(): void
    {
        $owner = $this->owner();
        $member = $this->memberOf($owner, 'viewer');
        $employee = $this->employeeIn($owner->currentTeam, $member);

        $this->actingAs($owner)->delete(route('employees.unlink', $employee))->assertRedirect();

        $this->assertNull($employee->fresh()->user_id);
    }

    public function test_cannot_link_a_user_who_is_not_on_the_team(): void
    {
        $owner = $this->owner();
        $outsider = User::factory()->create();
        $employee = $this->employeeIn($owner->currentTeam);

        $this->actingAs($owner)
            ->post(route('employees.link', $employee), ['user_id' => $outsider->id])
            ->assertSessionHasErrors('user_id');

        $this->assertNull($employee->fresh()->user_id);
    }

    public function test_one_account_links_to_only_one_employee_per_team(): void
    {
        $owner = $this->owner();
        $member = $this->memberOf($owner, 'viewer');
        $this->employeeIn($owner->currentTeam, $member);
        $second = $this->employeeIn($owner->currentTeam, null, ['email' => 'second@example.test']);

        $this->actingAs($owner)
            ->post(route('employees.link', $second), ['user_id' => $member->id])
            ->assertSessionHasErrors('user_id');

        $this->assertNull($second->fresh()->user_id);
    }

    public function test_database_enforces_one_link_per_team(): void
    {
        $owner = $this->owner();
        $member = $this->memberOf($owner, 'viewer');
        $this->employeeIn($owner->currentTeam, $member);

        $this->expectException(QueryException::class);
        $this->employeeIn($owner->currentTeam, $member, ['email' => 'dup@example.test']);
    }

    public function test_signing_in_links_the_employee_whose_email_matches_the_verified_account(): void
    {
        $owner = $this->owner();
        $member = $this->memberOf($owner, 'viewer');
        $employee = $this->employeeIn($owner->currentTeam, null, ['email' => strtoupper($member->email)]);

        Event::dispatch(new Login('web', $member, false));

        $this->assertSame($member->id, $employee->fresh()->user_id, 'matching is case-insensitive');
    }

    public function test_a_real_password_login_links_by_email(): void
    {
        $owner = $this->owner();
        $member = $this->memberOf($owner, 'viewer');
        $employee = $this->employeeIn($owner->currentTeam, null, ['email' => $member->email]);

        $this->post('/login', ['email' => $member->email, 'password' => 'password'])->assertRedirect();

        $this->assertSame($member->id, $employee->fresh()->user_id);
    }

    public function test_an_unverified_account_is_never_matched_by_email(): void
    {
        $owner = $this->owner();
        $member = $this->memberOf($owner, 'viewer');
        $member->forceFill(['email_verified_at' => null])->save();
        $employee = $this->employeeIn($owner->currentTeam, null, ['email' => $member->email]);

        Event::dispatch(new Login('web', $member->fresh(), false));

        $this->assertNull($employee->fresh()->user_id);
    }

    public function test_a_matching_email_in_a_team_the_user_does_not_belong_to_is_ignored(): void
    {
        $owner = $this->owner();
        $outsider = User::factory()->withPersonalTeam()->create();
        $employee = $this->employeeIn($owner->currentTeam, null, ['email' => $outsider->email]);

        Event::dispatch(new Login('web', $outsider, false));

        $this->assertNull($employee->fresh()->user_id);
    }

    public function test_an_already_linked_employee_is_not_relinked_to_someone_else(): void
    {
        $owner = $this->owner();
        $first = $this->memberOf($owner, 'viewer');
        $second = $this->memberOf($owner, 'viewer');
        $employee = $this->employeeIn($owner->currentTeam, $first, ['email' => $second->email]);

        Event::dispatch(new Login('web', $second, false));

        $this->assertSame($first->id, $employee->fresh()->user_id);
    }

    public function test_creating_an_employee_links_an_existing_matching_team_member(): void
    {
        $owner = $this->owner();
        $member = $this->memberOf($owner, 'viewer');

        $this->actingAs($owner)->post(route('employees.store'), [
            'first_name' => 'New', 'last_name' => 'Hire', 'email' => $member->email,
            'employment_type' => 'full_time', 'status' => 'active',
        ])->assertRedirect();

        $this->assertSame($member->id, Employee::where('first_name', 'New')->firstOrFail()->user_id);
    }

    public function test_the_employee_page_offers_the_link_action_to_admins_only(): void
    {
        $owner = $this->owner();
        $editor = $this->memberOf($owner, 'editor');
        $employee = $this->employeeIn($owner->currentTeam);

        $this->actingAs($owner)->get(route('employees.show', $employee))->assertSee('Link to account');
        $this->actingAs($editor)->get(route('employees.show', $employee))->assertDontSee('Link to account');
    }
}
