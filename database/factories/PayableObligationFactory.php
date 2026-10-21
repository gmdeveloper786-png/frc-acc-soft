<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Models\PayableObligation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayableObligation>
 */
class PayableObligationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_expense_transaction_id' => OperationalTransaction::factory(),
            'source_journal_entry_id' => JournalEntry::factory(),
            'branch_id' => Branch::factory(),
            'payable_account_id' => Account::factory(),
            'vendor_id' => null,
            'original_amount' => '100.0000',
            'obligation_date' => '2026-01-15',
            'source_reference' => fake()->bothify('EXP-####'),
        ];
    }
}
