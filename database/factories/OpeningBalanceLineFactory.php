<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\OpeningBalanceBatch;
use App\Models\OpeningBalanceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpeningBalanceLine>
 */
class OpeningBalanceLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'opening_balance_batch_id' => OpeningBalanceBatch::factory(), 'account_id' => Account::factory(),
            'debit' => '0.00', 'credit' => '0.00',
        ];
    }
}
