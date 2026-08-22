<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'client_id' => User::factory(),
            'booking_id' => null,
            'rating' => fake()->numberBetween(3, 5),
            'comment' => fake()->sentence(),
            'status' => 'pending',
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'approved',
        ]);
    }

    public function hidden(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'hidden',
        ]);
    }
}
