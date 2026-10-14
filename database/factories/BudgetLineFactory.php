<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Budget;
use App\Models\BudgetLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BudgetLine> */
class BudgetLineFactory extends Factory
{
    public function definition(): array
    {
        return ['budget_id' => Budget::factory(), 'account_id' => Account::factory()->state(['account_type' => 'expense', 'financial_statement' => 'income_expenditure']), 'accounting_period_id' => AccountingPeriod::factory(), 'amount' => '100.0000'];
    }
}
