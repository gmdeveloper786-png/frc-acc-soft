<?php

namespace Database\Seeders;

use App\Models\VoucherType;
use Illuminate\Database\Seeder;

class VoucherTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'CR', 'name' => 'Cash Receipt'], ['code' => 'CP', 'name' => 'Cash Payment'],
            ['code' => 'BR', 'name' => 'Bank Receipt'], ['code' => 'BP', 'name' => 'Bank Payment'],
            ['code' => 'JV', 'name' => 'Journal Voucher'], ['code' => 'CV', 'name' => 'Contra Voucher'],
            ['code' => 'FR', 'name' => 'Fee Receipt'], ['code' => 'DR', 'name' => 'Donation Receipt'],
            ['code' => 'PB', 'name' => 'Purchase Bill'], ['code' => 'SV', 'name' => 'Salary Voucher'],
        ];

        foreach ($types as $type) {
            VoucherType::query()->firstOrCreate(
                ['code' => $type['code']],
                [...$type, 'prefix' => $type['code'], 'year_format' => 'Y', 'padding' => 5, 'reset_period' => 'yearly', 'is_active' => true, 'is_system' => true],
            );
        }
    }
}
