<?php

namespace Database\Factories;

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BankStatementLine> */
class BankStatementLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'bank_statement_id' => BankStatement::factory(),
            'financial_account_id' => fn (array $attributes): int => BankStatement::query()->findOrFail($attributes['bank_statement_id'])->financial_account_id,
            'row_number' => fake()->numberBetween(2, 500),
            'transaction_date' => '2026-01-15',
            'description' => fake()->sentence(3),
            'reference' => null,
            'amount' => '100.0000',
            'status' => BankStatementLine::UNMATCHED,
        ];
    }
}
