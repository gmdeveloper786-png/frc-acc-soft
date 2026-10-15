<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\ImprestFloat;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ImprestFloat> */
class ImprestFloatFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(), 'account_id' => Account::factory(), 'float_amount' => '10000.0000',
            'source_financial_account_id' => FinancialAccount::factory()->state(['type' => 'bank']),
            'custodian_name' => fake()->name(), 'is_active' => true, 'created_by' => User::factory(),
        ];
    }
}
