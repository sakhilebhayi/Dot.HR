<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 *
 * Generates clearly fictional employee records only (fake() library data) —
 * never real-person data. Keeps the same minimal PII surface as the model:
 * name and work contact fields only, no ID numbers/salary/medical data.
 */
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();

        return [
            'team_id' => Team::factory(),
            'position_id' => Position::factory(),
            'user_id' => null,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'employment_type' => fake()->randomElement(['full_time', 'part_time', 'contractor']),
            // "On leave" is computed from approved leave, never stored: see onLeave().
            'status' => 'active',
            'start_date' => fake()->dateTimeBetween('-4 years', '-1 month'),
            'end_date' => null,
            'created_by' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active']);
    }

    public function terminated(): static
    {
        return $this->state(fn () => ['status' => 'terminated']);
    }

    /** Active, with approved leave covering today (that is what "on leave" means here). */
    public function onLeave(): static
    {
        return $this->state(fn () => ['status' => 'active'])->afterCreating(function (Employee $employee) {
            LeaveRequest::factory()->approved()->create([
                'team_id' => $employee->team_id,
                'employee_id' => $employee->id,
                'start_date' => now()->subDay()->toDateString(),
                'end_date' => now()->addDay()->toDateString(),
            ]);
        });
    }
}
