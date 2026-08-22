<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\PropertyImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyImage>
 */
class PropertyImageFactory extends Factory
{
    protected $model = PropertyImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'image_path' => 'properties/'.fake()->unique()->lexify('??????????').'.jpg',
            'room_type' => 'bedroom',
            'room_name' => 'Master bedroom',
            'alt_text' => null,
            'order' => 0,
            'is_cover' => false,
        ];
    }

    public function cover(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_cover' => true,
        ]);
    }

    public function roomType(string $roomType, ?string $roomName = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'room_type' => $roomType,
            'room_name' => $roomName,
        ]);
    }
}
