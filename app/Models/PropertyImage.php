<?php

namespace App\Models;

use Database\Factories\PropertyImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageManagerInterface;

class PropertyImage extends Model
{
    /** @use HasFactory<PropertyImageFactory> */
    use HasFactory;

    protected $fillable = ['property_id', 'image_path', 'room_type', 'room_name', 'alt_text', 'order', 'is_cover'];

    /**
     * @return array<string, string>
     */
    public static function roomTypeOptions(): array
    {
        return [
            'bedroom' => 'Bedroom',
            'kitchen' => 'Kitchen',
            'bathroom' => 'Bathroom',
            'living-room' => 'Living room',
            'dining-room' => 'Dining room',
            'workspace' => 'Workspace',
            'exterior' => 'Exterior',
            'amenity' => 'Amenity',
            'other' => 'Other room',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * How the photo is named in the admin panel, in place of its id.
     */
    public function getDisplayNameAttribute(): string
    {
        $room = $this->room_name ?: (static::roomTypeOptions()[$this->room_type] ?? 'Room photo');

        return trim(($this->property?->title ? $this->property->title.' · ' : '').$room);
    }

    /**
     * The thumbnail generated on save, falling back to the original photo when
     * it has not been generated (yet).
     */
    public function getThumbnailPathAttribute(): ?string
    {
        $path = $this->image_path;

        if (! $path) {
            return null;
        }

        $thumbnail = static::thumbnailPathFor($path);

        return Storage::disk('public')->exists($thumbnail) ? $thumbnail : $path;
    }

    /**
     * Where the thumbnail of a photo lives. Thumbnails are always JPEG so they
     * render everywhere, whatever format the original was uploaded in.
     */
    public static function thumbnailPathFor(string $imagePath): string
    {
        return 'properties/thumbs/'.pathinfo($imagePath, PATHINFO_FILENAME).'.jpg';
    }

    protected static function booted(): void
    {
        static::saved(function (PropertyImage $image): void {
            $image->property?->syncLayoutCountsFromImages();
            $image->generateThumbnail();
        });

        static::deleted(function (PropertyImage $image): void {
            $image->property?->syncLayoutCountsFromImages();

            if ($image->image_path) {
                Storage::disk('public')->delete([
                    $image->image_path,
                    static::thumbnailPathFor($image->image_path),
                ]);
            }
        });
    }

    /**
     * Write a small JPEG copy of the photo so listings do not have to download
     * the full-size original.
     */
    protected function generateThumbnail(): void
    {
        $disk = Storage::disk('public');
        $path = $this->image_path;

        if (! $path || ! $disk->exists($path)) {
            return;
        }

        try {
            $image = static::imageManager()
                ->decodeBinary((string) $disk->get($path))
                ->cover(800, 600);

            $disk->put(
                static::thumbnailPathFor($path),
                (string) $image->encode(new JpegEncoder(quality: 80)),
            );
        } catch (\Throwable $exception) {
            // A photo the driver cannot decode simply keeps using the original.
            report($exception);
        }
    }

    /**
     * Imagick reads more formats than GD (AVIF and HEIC among them), so prefer
     * it when the extension is installed.
     */
    protected static function imageManager(): ImageManagerInterface
    {
        return ImageManager::usingDriver(
            extension_loaded('imagick') ? new ImagickDriver : new GdDriver,
        );
    }
}
