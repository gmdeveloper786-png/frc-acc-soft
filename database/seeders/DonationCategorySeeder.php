<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\DonationCategory;
use App\Models\Fund;
use Illuminate\Database\Seeder;

class DonationCategorySeeder extends Seeder
{
    public function run(): void
    {
        $gen = Fund::query()->where('code', 'GEN')->first();
        $zkt = Fund::query()->where('code', 'ZKT')->first();
        $acc43101 = Account::query()->where('code', '43101')->first();
        $acc43102 = Account::query()->where('code', '43102')->first();
        $acc43105 = Account::query()->where('code', '43105')->first();

        DonationCategory::query()->updateOrCreate(
            ['slug' => 'general-donation'],
            [
                'name' => 'General Donation',
                'fund_id' => $gen?->id,
                'income_account_id' => $acc43101?->id,
                'is_active' => true,
            ]
        );

        DonationCategory::query()->updateOrCreate(
            ['slug' => 'zakat'],
            [
                'name' => 'Zakat',
                'fund_id' => $zkt?->id,
                'income_account_id' => $acc43102?->id,
                'is_active' => true,
            ]
        );

        DonationCategory::query()->updateOrCreate(
            ['slug' => 'new-frc-project-donation'],
            [
                'name' => 'New FRC Project Donation',
                'fund_id' => null,
                'income_account_id' => $acc43105?->id,
                'is_active' => true,
            ]
        );
    }
}
