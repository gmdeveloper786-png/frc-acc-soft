<?php

namespace Database\Factories;

use App\Enums\DepreciationConvention;
use App\Enums\DepreciationRunStatus;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\DepreciationRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DepreciationRun> */
class DepreciationRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(), 'accounting_period_id' => AccountingPeriod::factory(), 'status' => DepreciationRunStatus::Draft,
            'convention' => DepreciationConvention::FullMonth, 'total_amount' => '0.0000', 'created_by' => User::factory(),
        ];
    }
}
