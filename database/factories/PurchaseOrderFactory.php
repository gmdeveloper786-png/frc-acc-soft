<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PurchaseOrder> */
class PurchaseOrderFactory extends Factory
{
    public function definition(): array
    {
        return ['po_number' => fake()->unique()->bothify('PO-TEST-#####'), 'vendor_id' => Vendor::factory(), 'branch_id' => Branch::factory(), 'po_date' => '2026-01-10', 'currency' => 'PKR', 'status' => 'draft', 'subtotal' => '100.0000', 'discount_total' => '0.0000', 'tax_total' => '0.0000', 'total_amount' => '100.0000', 'created_by' => User::factory()];
    }
}
