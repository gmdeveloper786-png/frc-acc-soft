<?php

namespace Database\Factories;

use App\Enums\EmploymentStatus;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Employee> */
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return ['employee_code' => fake()->unique()->bothify('EMP-T####'), 'name' => 'Test Employee '.fake()->unique()->numerify('####'), 'branch_id' => Branch::factory(), 'designation' => 'Test Role', 'employment_status' => EmploymentStatus::Active, 'created_by' => User::factory()];
    }
}
