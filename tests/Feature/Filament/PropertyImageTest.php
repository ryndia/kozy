<?php

namespace Tests\Feature\Filament;

use App\Models\Property;
use App\Models\PropertyImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PropertyImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_photo_writes_a_thumbnail(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('properties/room.jpg', $this->jpeg());

        $image = PropertyImage::factory()->create([
            'image_path' => 'properties/room.jpg',
        ]);

        Storage::disk('public')->assertExists('properties/thumbs/room.jpg');
        $this->assertSame('properties/thumbs/room.jpg', $image->thumbnail_path);
    }

    public function test_the_thumbnail_falls_back_to_the_original_photo(): void
    {
        Storage::fake('public');

        $image = PropertyImage::factory()->create([
            'image_path' => 'properties/missing.jpg',
        ]);

        $this->assertSame('properties/missing.jpg', $image->thumbnail_path);
    }

    public function test_deleting_a_photo_removes_its_files(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('properties/room.jpg', $this->jpeg());

        $image = PropertyImage::factory()->create([
            'image_path' => 'properties/room.jpg',
        ]);

        $image->delete();

        Storage::disk('public')->assertMissing('properties/room.jpg');
        Storage::disk('public')->assertMissing('properties/thumbs/room.jpg');
    }

    public function test_the_property_cover_uses_the_gallery(): void
    {
        Storage::fake('public');
        $property = Property::factory()->create();

        PropertyImage::factory()->for($property)->create([
            'image_path' => 'properties/first.jpg',
            'order' => 1,
        ]);
        PropertyImage::factory()->for($property)->cover()->create([
            'image_path' => 'properties/cover.jpg',
            'order' => 2,
        ]);

        $this->assertSame('properties/cover.jpg', $property->fresh()->cover_image_path);
    }

    private function jpeg(): string
    {
        $canvas = imagecreatetruecolor(1200, 900);
        ob_start();
        imagejpeg($canvas);
        $contents = (string) ob_get_clean();
        imagedestroy($canvas);

        return $contents;
    }
}
