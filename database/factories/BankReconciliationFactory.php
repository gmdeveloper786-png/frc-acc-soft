<?php

namespace Database\Factories;

use App\Models\BankReconciliation;
use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BankReconciliation> */
class BankReconciliationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'financial_account_id' => FinancialAccount::factory()->state(['type' => 'bank']),
            'branch_id' => fn (array $attributes): int => FinancialAccount::query()->findOrFail($attributes['financial_account_id'])->branch_id,
            'statement_date' => '2026-01-31',
            'statement_closing_balance' => '0.0000',
            'status' => BankReconciliation::DRAFT,
            'open_key' => '1',
            'prepared_by' => User::factory(),
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => BankReconciliation::APPROVED, 'open_key' => null, 'approved_by' => User::factory(), 'approved_at' => now()]);
    }
}
