<?php

namespace Database\Factories;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vendor> */
class VendorFactory extends Factory
{
    public function definition(): array
    {
        return ['vendor_code' => fake()->unique()->bothify('VEN-####'), 'name' => fake()->company(), 'email' => fake()->safeEmail(), 'is_active' => true];
    }
}
