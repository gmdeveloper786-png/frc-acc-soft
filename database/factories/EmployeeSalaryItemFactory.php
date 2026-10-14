<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeSalaryItem;
use App\Models\PayrollComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmployeeSalaryItem> */
class EmployeeSalaryItemFactory extends Factory
{
    public function definition(): array
    {
        return ['employee_id' => Employee::factory(), 'payroll_component_id' => PayrollComponent::factory(), 'amount' => '100.0000'];
    }
}
