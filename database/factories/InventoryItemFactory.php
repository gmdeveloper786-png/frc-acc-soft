<?php

namespace Database\Factories;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryItem> */
class InventoryItemFactory extends Factory
{
    public function definition(): array
    {
        return ['item_code' => fake()->unique()->bothify('ITM-#####'), 'name' => fake()->words(2, true), 'category_id' => InventoryCategory::factory(), 'unit_of_measure' => 'pcs', 'minimum_stock_level' => '0.0000', 'is_active' => true, 'created_by' => User::factory()];
    }
}
