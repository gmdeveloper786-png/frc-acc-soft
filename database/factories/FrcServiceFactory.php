<?php

namespace Database\Factories;

use App\Models\FrcService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FrcService>
 */
class FrcServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'frc_service_id' => fake()->unique()->numberBetween(1, 1000000),
            'name' => fake()->words(3, true),
            'status' => 'publish',
            'last_synced_at' => now(),
        ];
    }
}
