<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\OpeningBalanceBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpeningBalanceBatch>
 */
class OpeningBalanceBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fiscal_year_id' => FiscalYear::factory(), 'branch_id' => Branch::factory(),
            'opening_date' => '2026-01-01', 'status' => 'draft',
            'total_debit' => '0.00', 'total_credit' => '0.00', 'difference' => '0.00',
        ];
    }
}
