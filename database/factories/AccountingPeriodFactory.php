<?php

namespace Database\Factories;

use App\Enums\AccountingPeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountingPeriod>
 */
class AccountingPeriodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fiscal_year_id' => FiscalYear::factory(), 'name' => 'January 2030', 'period_number' => 1,
            'start_date' => '2030-01-01', 'end_date' => '2030-01-31', 'status' => AccountingPeriodStatus::Draft,
        ];
    }
}
