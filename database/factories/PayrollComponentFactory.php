<?php

namespace Database\Factories;

use App\Enums\PayrollComponentCategory;
use App\Enums\PayrollComponentType;
use App\Models\PayrollComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PayrollComponent> */
class PayrollComponentFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('CMP-T####'), 'name' => 'Test Component '.fake()->unique()->numerify('####'), 'type' => PayrollComponentType::Earning, 'category' => PayrollComponentCategory::Allowance, 'is_active' => true, 'sort_order' => 500];
    }

    public function deduction(): static
    {
        return $this->state(fn (): array => ['type' => PayrollComponentType::Deduction, 'category' => PayrollComponentCategory::OtherDeduction]);
    }
}
