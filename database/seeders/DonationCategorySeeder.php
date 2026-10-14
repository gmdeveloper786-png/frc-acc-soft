<?php

namespace Database\Seeders;

use App\Models\DonationCategory;
use Illuminate\Database\Seeder;

class DonationCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach (['General Donation', 'Zakat', 'New FRC Project Donation'] as $name) {
            DonationCategory::query()->firstOrCreate(['name' => $name], ['slug' => str($name)->slug(), 'is_active' => true]);
        }
    }
}
