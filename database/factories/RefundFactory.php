<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'payment_id' => null,
            'amount' => fake()->numberBetween(100, 900),
            'fee_amount' => 0,
            'currency' => 'MUR',
            'status' => Refund::STATUS_PENDING,
            'method' => 'card',
            'reason' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Refund::STATUS_COMPLETED,
            'processed_at' => now(),
            'provider_reference' => 'RE-'.fake()->unique()->lexify('??????'),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Refund::STATUS_FAILED,
            'failure_reason' => 'The provider declined the refund.',
        ]);
    }
}
