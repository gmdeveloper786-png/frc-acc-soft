<?php

namespace Database\Factories;

use App\Models\BankStatement;
use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BankStatement> */
class BankStatementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'financial_account_id' => FinancialAccount::factory()->state(['type' => 'bank']),
            'branch_id' => fn (array $attributes): int => FinancialAccount::query()->findOrFail($attributes['financial_account_id'])->branch_id,
            'status' => BankStatement::IMPORTED,
            'original_filename' => 'statement.csv',
            'stored_path' => 'bank-statements/'.fake()->uuid().'.csv',
            'file_extension' => 'csv',
            'file_hash' => hash('sha256', fake()->unique()->uuid()),
            'period_from' => '2026-01-01',
            'period_to' => '2026-01-31',
            'opening_balance' => '0.0000',
            'closing_balance' => '0.0000',
            'uploaded_by' => User::factory(),
            'imported_at' => now(),
        ];
    }
}
