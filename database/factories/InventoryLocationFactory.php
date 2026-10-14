<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\InventoryLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryLocation> */
class InventoryLocationFactory extends Factory
{
    public function definition(): array
    {
        return ['branch_id' => Branch::factory(), 'name' => fake()->unique()->bothify('Store ????-###'), 'is_active' => true];
    }
}
