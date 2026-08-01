<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds a demo team with clearly fictional example data — no real people.
 * Names below are generic placeholders (Test User) or generated via the
 * model factories' fake() calls; none reference a real individual.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::factory()->withPersonalTeam()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $team = $user->currentTeam;

        $positions = collect([
            ['title' => 'Operations Manager', 'department' => 'Operations'],
            ['title' => 'Warehouse Associate', 'department' => 'Warehouse'],
            ['title' => 'HR Coordinator', 'department' => 'People'],
            ['title' => 'Maintenance Technician', 'department' => 'Maintenance'],
        ])->map(fn (array $attrs) => Position::create([
            'team_id' => $team->id,
            'title' => $attrs['title'],
            'department' => $attrs['department'],
            'description' => 'Demo position seeded for local development.',
        ]));

        $employees = Employee::factory()
            ->count(12)
            ->state(fn () => [
                'team_id' => $team->id,
                'position_id' => $positions->random()->id,
                'created_by' => $user->id,
            ])
            ->create();

        // A few pending leave requests so the dashboard has something to show.
        $employees->take(4)->each(function (Employee $employee) use ($team, $user) {
            LeaveRequest::factory()->create([
                'team_id' => $team->id,
                'employee_id' => $employee->id,
                'requested_by' => $user->id,
            ]);
        });

        // One approved leave request for variety.
        LeaveRequest::factory()->approved()->create([
            'team_id' => $team->id,
            'employee_id' => $employees->first()->id,
            'requested_by' => $user->id,
            'reviewed_by' => $user->id,
        ]);
    }
}
