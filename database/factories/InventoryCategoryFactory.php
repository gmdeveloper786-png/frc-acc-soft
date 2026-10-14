<?php

namespace Database\Factories;

use App\Models\InventoryCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryCategory> */
class InventoryCategoryFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->bothify('Category ????-###'), 'is_active' => true];
    }
}
