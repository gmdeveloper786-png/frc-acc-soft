<?php

namespace Database\Factories;

use App\Enums\PayrollRunStatus;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PayrollRun> */
class PayrollRunFactory extends Factory
{
    public function definition(): array
    {
        return ['branch_id' => Branch::factory(), 'accounting_period_id' => AccountingPeriod::factory(), 'status' => PayrollRunStatus::Draft, 'active_key' => '1', 'source' => 'manual', 'gross_amount' => '0.0000', 'total_deductions' => '0.0000', 'net_amount' => '0.0000', 'created_by' => User::factory()];
    }
}
