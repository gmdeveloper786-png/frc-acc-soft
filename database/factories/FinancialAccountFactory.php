<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Branch;
use App\Models\FinancialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinancialAccount> */
class FinancialAccountFactory extends Factory
{
    public function definition(): array
    {
        return ['type' => 'cash', 'name' => fake()->unique()->words(2, true), 'account_id' => Account::factory(), 'branch_id' => Branch::factory(), 'currency' => 'PKR', 'is_active' => true];
    }
}
