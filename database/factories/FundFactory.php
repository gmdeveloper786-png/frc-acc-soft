<?php

namespace Database\Factories;

use App\Models\Fund;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Fund> */
class FundFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('F??##'), 'name' => fake()->words(2, true).' Fund',
            'type' => Fund::TYPE_UNRESTRICTED, 'category' => 'general', 'is_active' => true,
        ];
    }

    public function restricted(string $category = 'donor'): static
    {
        return $this->state(['type' => Fund::TYPE_RESTRICTED, 'category' => $category]);
    }

    public function grant(string $amount, string $start = '2026-01-01', string $end = '2026-12-31'): static
    {
        return $this->state(['type' => Fund::TYPE_RESTRICTED, 'category' => Fund::CATEGORY_GRANT, 'donor_name' => fake()->company(), 'grant_amount' => $amount, 'start_date' => $start, 'end_date' => $end]);
    }
}
