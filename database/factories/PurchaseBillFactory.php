<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Branch;
use App\Models\PurchaseBill;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PurchaseBill> */
class PurchaseBillFactory extends Factory
{
    public function definition(): array
    {
        return ['vendor_id' => Vendor::factory(), 'branch_id' => Branch::factory(), 'payable_account_id' => Account::factory(), 'bill_number' => fake()->unique()->bothify('INV-####'), 'invoice_date' => '2026-01-10', 'due_date' => '2026-02-10', 'total_amount' => '100.0000', 'status' => 'draft', 'created_by' => User::factory()];
    }
}
