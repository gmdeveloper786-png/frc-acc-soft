<?php

namespace Database\Factories;

use App\Models\FrcPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FrcPayment>
 */
class FrcPaymentFactory extends Factory
{
    protected $model = FrcPayment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = fake()->unique()->numberBetween(1, 10000000);

        return [
            'frc_payment_id' => $id,
            'receipt_number' => 'FRC-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT),
            'frc_enrollment_id' => fake()->numberBetween(1, 10000000),
            'amount' => '1000.0000',
            'payment_method' => 'cash',
            'source_status' => 'paid',
            'payment_date' => now()->toDateString(),
            'verified_at' => now(),
            'source_created_at' => now(),
            'source_updated_at' => now(),
            'content_hash' => hash('sha256', fake()->uuid()),
            'posting_status' => 'unposted',
        ];
    }
}
