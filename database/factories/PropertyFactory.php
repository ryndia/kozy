<?php

namespace Database\Factories;

use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    protected $model = Property::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->streetName().' Retreat';

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 100000),
            'description' => fake()->paragraph(),
            'address' => fake()->address(),
            'price_per_night' => fake()->numberBetween(60, 400),
            'currency' => 'USD',
            'max_guests' => 4,
            'bedrooms' => 2,
            'kitchens' => 1,
            'bathrooms' => 1,
            'amenities' => ['WiFi', 'Kitchen'],
            'features' => ['WiFi', 'Pool'],
            'status' => 'active',
            'lat' => fake()->latitude(),
            'lng' => fake()->longitude(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'inactive',
        ]);
    }
}
