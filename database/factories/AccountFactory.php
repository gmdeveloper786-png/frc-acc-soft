<?php

namespace Database\Factories;

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\FinancialStatement;
use App\Enums\NormalBalance;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => $this->faker->unique()->numerify('######'), 'name' => $this->faker->words(3, true),
            'account_type' => AccountType::Asset, 'level' => AccountLevel::Ledger,
            'normal_balance' => NormalBalance::Debit, 'financial_statement' => FinancialStatement::BalanceSheet,
            'is_postable' => true, 'is_active' => true, 'sort_order' => 0,
        ];
    }
}
