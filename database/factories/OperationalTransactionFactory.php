<?php

namespace Database\Factories;

use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Models\Branch;
use App\Models\OperationalTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OperationalTransaction> */
class OperationalTransactionFactory extends Factory
{
    public function definition(): array
    {
        return ['type' => OperationalTransactionType::CashReceipt, 'status' => OperationalTransactionStatus::Draft, 'branch_id' => Branch::factory(), 'transaction_date' => '2026-01-15', 'party_name' => fake()->name(), 'amount' => '100.0000', 'narration' => fake()->sentence(), 'created_by' => User::factory()];
    }
}
