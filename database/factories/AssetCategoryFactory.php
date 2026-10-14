<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AssetCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AssetCategory> */
class AssetCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('CAT-####'), 'name' => fake()->words(2, true), 'default_useful_life_months' => 60,
            'asset_account_id' => Account::factory(),
            'accumulated_depreciation_account_id' => Account::factory()->state(['normal_balance' => 'credit']),
            'depreciation_expense_account_id' => Account::factory()->state(['account_type' => 'expense', 'financial_statement' => 'income_expenditure']),
            'is_active' => true,
        ];
    }
}
