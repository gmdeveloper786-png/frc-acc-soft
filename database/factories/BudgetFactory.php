<?php

namespace Database\Factories;

use App\Enums\BudgetStatus;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Budget> */
class BudgetFactory extends Factory
{
    public function definition(): array
    {
        return ['fiscal_year_id' => FiscalYear::factory(), 'branch_id' => Branch::factory(), 'service_id' => null, 'name' => 'Budget '.fake()->unique()->numerify('####'), 'version' => 1, 'status' => BudgetStatus::Draft, 'total_amount' => '0.0000', 'created_by' => User::factory()];
    }
}
