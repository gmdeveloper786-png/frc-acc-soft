<?php

namespace Database\Factories;

use App\Models\FrcEnrollment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FrcEnrollment>
 */
class FrcEnrollmentFactory extends Factory
{
    protected $model = FrcEnrollment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'frc_enrollment_id' => fake()->unique()->numberBetween(1, 10000000),
            'source_status' => 'active',
            'payment_status' => 'partial',
            'final_total' => '10000.0000',
            'discount_amount' => '0.0000',
            'paid_amount' => '0.0000',
            'remaining_amount' => '10000.0000',
            'source_approved_at' => now(),
            'source_created_at' => now(),
            'source_updated_at' => now(),
            'content_hash' => hash('sha256', fake()->uuid()),
        ];
    }
}
