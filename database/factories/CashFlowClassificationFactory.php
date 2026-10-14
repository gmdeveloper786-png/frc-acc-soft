<?php

namespace Database\Factories;

use App\Enums\CashFlowActivity;
use App\Models\Account;
use App\Models\CashFlowClassification;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CashFlowClassification> */
class CashFlowClassificationFactory extends Factory
{
    public function definition(): array
    {
        return ['account_id' => Account::factory(), 'activity' => CashFlowActivity::Operating];
    }
}
