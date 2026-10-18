<?php

namespace Tests\Feature\Hr;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\BuildsHrTeams;
use Tests\TestCase;

class TeamScopeFailClosedTest extends TestCase
{
    use BuildsHrTeams;
    use RefreshDatabase;

    public function test_an_authenticated_user_without_a_team_sees_nothing(): void
    {
        $owner = $this->owner();
        $this->employeeIn($owner->currentTeam);
        $teamless = User::factory()->create(['current_team_id' => null]);

        $this->actingAs($teamless);

        $this->assertSame(0, Employee::count());
        $this->assertSame(0, Position::count());
        $this->assertSame(0, LeaveRequest::count());
        $this->assertSame(0, LeaveType::count());
    }

    public function test_a_current_team_the_user_no_longer_belongs_to_shows_nothing(): void
    {
        $owner = $this->owner();
        $this->employeeIn($owner->currentTeam);
        $removed = User::factory()->create(['current_team_id' => $owner->currentTeam->id]); // never a member

        $this->actingAs($removed);

        $this->assertSame(0, Employee::count());
    }

    public function test_with_no_authenticated_user_queries_fail_closed_too(): void
    {
        $owner = $this->owner();
        $this->employeeIn($owner->currentTeam);
        Auth::logout();

        $this->assertSame(0, Employee::count(), 'jobs and console code must opt out of the scope explicitly');
    }

    public function test_the_unscoped_helper_is_the_explicit_opt_out_and_is_not_sticky(): void
    {
        $owner = $this->owner();
        $this->employeeIn($owner->currentTeam);
        $this->employeeIn($this->owner()->currentTeam);
        Auth::logout();

        $this->assertSame(2, Employee::withoutTeamScope(fn () => Employee::count()));
        $this->assertSame(0, Employee::count());
    }

    public function test_the_unscoped_helper_is_released_even_if_the_callback_throws(): void
    {
        $this->employeeIn($this->owner()->currentTeam);
        Auth::logout();

        try {
            Employee::withoutTeamScope(function () {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, Employee::count());
    }

    public function test_a_normal_member_still_sees_only_their_current_team(): void
    {
        $owner = $this->owner();
        $mine = $this->employeeIn($owner->currentTeam);
        $this->employeeIn($this->owner()->currentTeam);

        $this->actingAs($owner);

        $this->assertSame([$mine->id], Employee::pluck('id')->all());
    }
}
