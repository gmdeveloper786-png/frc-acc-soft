<?php

namespace Database\Factories;

use App\Enums\FixedAssetStatus;
use App\Models\AssetCategory;
use App\Models\Branch;
use App\Models\FixedAsset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FixedAsset> */
class FixedAssetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'asset_code' => fake()->unique()->bothify('FA-TEST-#####'), 'name' => fake()->words(3, true), 'category_id' => AssetCategory::factory(),
            'acquisition_date' => '2026-01-01', 'in_service_date' => '2026-01-01', 'acquisition_cost' => '12000.0000', 'residual_value' => '0.0000',
            'useful_life_months' => 12, 'opening_accumulated_depreciation' => '0.0000', 'branch_id' => Branch::factory(),
            'status' => FixedAssetStatus::Draft, 'created_by' => User::factory(),
        ];
    }

    public function configure(): static
    {
        // The asset always carries the ledgers of its category, as registration does.
        return $this->afterMaking(function (FixedAsset $asset): void {
            $category = $asset->category;
            $asset->asset_account_id ??= $category?->asset_account_id;
            $asset->accumulated_depreciation_account_id ??= $category?->accumulated_depreciation_account_id;
            $asset->depreciation_expense_account_id ??= $category?->depreciation_expense_account_id;
        });
    }

    public function active(): static
    {
        return $this->state(['status' => FixedAssetStatus::Active, 'activated_at' => now()]);
    }
}
