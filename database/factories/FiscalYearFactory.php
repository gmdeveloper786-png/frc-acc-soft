<?php

namespace Database\Factories;

use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalYear>
 */
class FiscalYearFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'FY '.$this->faker->unique()->numberBetween(2030, 2090),
            'start_date' => '2030-01-01', 'end_date' => '2030-12-31',
            'status' => FiscalYearStatus::Draft, 'is_current' => false,
        ];
    }
}
