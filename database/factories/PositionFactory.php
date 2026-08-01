<?php

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Position>
 */
class PositionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'title' => fake()->randomElement([
                'Site Supervisor', 'Warehouse Associate', 'HR Coordinator',
                'Maintenance Technician', 'Operations Manager', 'Payroll Clerk',
                'Safety Officer', 'Logistics Planner', 'Customer Support Agent',
            ]),
            'department' => fake()->randomElement(['Operations', 'Warehouse', 'People', 'Maintenance', 'Support']),
            'description' => fake()->sentence(12),
        ];
    }
}
