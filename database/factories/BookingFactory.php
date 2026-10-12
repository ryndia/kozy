<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $checkIn = today()->addDays(7);

        return [
            'property_id' => Property::factory(),
            'client_id' => User::factory(),
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->addDays(3)->toDateString(),
            'guests' => 2,
            'status' => Booking::STATUS_PENDING,
            'total_price' => 300,
        ];
    }

    public function past(): static
    {
        return $this->state(fn (array $attributes): array => [
            'check_in' => today()->subDays(10)->toDateString(),
            'check_out' => today()->subDays(7)->toDateString(),
            'status' => Booking::STATUS_COMPLETED,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Booking::STATUS_CANCELLED,
        ]);
    }

    public function withPayment(string $status = Payment::STATUS_PENDING): static
    {
        return $this->afterCreating(function (Booking $booking) use ($status): void {
            $booking->payment()->create([
                'amount' => $booking->total_price,
                'method' => 'card',
                'status' => $status,
                'paid_at' => $status === Payment::STATUS_PAID ? now() : null,
            ]);
        });
    }
}
