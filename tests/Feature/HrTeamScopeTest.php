<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Direct proof that HasTeamScope's global scope (app/Models/Concerns/
 * HasTeamScope.php) is load-bearing on its own, independent of any Policy
 * or controller-level where('team_id', ...) call. Mirrors Dot.Notify's
 * NotifyTeamScopeTest.php (team-scoped sibling of Dot.Finance's
 * HasUserScope pilot), adapted to Employee — the model in this platform
 * with the most person-sensitive data (wiki.md §2).
 *
 * HrAuthorizationTest.php covers the HTTP-layer behavior (Policies,
 * route-model-binding 404s); this test isolates the scope itself by
 * querying the model directly with no explicit where('team_id', ...)
 * anywhere in sight, proving a forgotten scope-less query in some future
 * controller still can't leak another team's rows.
 */
class HrTeamScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_alone_blocks_cross_team_access_even_without_an_explicit_where(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();

        $employee = Employee::create([
            'team_id' => $owner->currentTeam->id,
            'first_name' => 'Owner',
            'last_name' => 'Employee',
            'employment_type' => 'full_time',
            'status' => 'active',
        ]);

        $this->actingAs($outsider);

        $this->assertNull(Employee::find($employee->id));
        $this->assertSame(0, Employee::query()->count());

        $this->actingAs($owner);

        $this->assertNotNull(Employee::find($employee->id));
        $this->assertSame(1, Employee::query()->count());
    }
}
