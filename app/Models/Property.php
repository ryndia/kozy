<?php

namespace App\Models;

use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use HasFactory;

    protected $fillable = [
        'title', 'slug', 'description', 'address', 'price_per_night', 'currency', 'max_guests', 'bedrooms', 'kitchens', 'bathrooms', 'amenities', 'features', 'status', 'lat', 'lng', 'check_in', 'check_out',
    ];

    protected $casts = [
        'amenities' => 'array',
        'features' => 'array',
        'check_in' => 'string',
        'check_out' => 'string',
    ];

    /**
     * Boot the model and set up event listeners
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->title);
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('title')) {
                $model->slug = Str::slug($model->title);
            }
        });
    }

    /**
     * @return HasMany<PropertyImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(PropertyImage::class)->orderBy('order');
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * The cover photo, falling back to the first photo in the gallery.
     */
    public function getCoverImagePathAttribute(): ?string
    {
        $images = $this->relationLoaded('images')
            ? $this->images
            : $this->images()->get();

        return ($images->firstWhere('is_cover', true) ?? $images->first())?->thumbnail_path;
    }

    public static function deriveBedroomCountFromImages(?array $images): int
    {
        return static::deriveRoomCountFromImages($images, 'bedroom');
    }

    public static function deriveKitchenCountFromImages(?array $images): int
    {
        return static::deriveRoomCountFromImages($images, 'kitchen');
    }

    public static function deriveBathroomCountFromImages(?array $images): int
    {
        return static::deriveRoomCountFromImages($images, 'bathroom');
    }

    public function syncLayoutCountsFromImages(?array $images = null): void
    {
        $imageStates = $images === null
            ? $this->images()
                ->get(['room_type', 'room_name'])
                ->map(fn (PropertyImage $image): array => [
                    'room_type' => $image->room_type,
                    'room_name' => $image->room_name,
                ])
                ->all()
            : $images;

        $counts = [
            'bedrooms' => static::deriveBedroomCountFromImages($imageStates),
            'kitchens' => static::deriveKitchenCountFromImages($imageStates),
            'bathrooms' => static::deriveBathroomCountFromImages($imageStates),
        ];

        if (
            (int) $this->bedrooms === $counts['bedrooms']
            && (int) $this->kitchens === $counts['kitchens']
            && (int) $this->bathrooms === $counts['bathrooms']
        ) {
            return;
        }

        $this->forceFill($counts)->saveQuietly();
    }

    protected static function deriveRoomCountFromImages(?array $images, string $roomType): int
    {
        $matchingImages = static::normalizeImageStates($images)
            ->filter(fn (array $image): bool => ($image['room_type'] ?? null) === $roomType);

        $namedRooms = $matchingImages
            ->map(fn (array $image): string => trim((string) ($image['room_name'] ?? '')))
            ->filter()
            ->map(fn (string $roomName): string => Str::lower($roomName))
            ->unique()
            ->count();

        $unnamedRooms = $matchingImages
            ->filter(fn (array $image): bool => blank(trim((string) ($image['room_name'] ?? ''))))
            ->count();

        return $namedRooms + $unnamedRooms;
    }

    protected static function normalizeImageStates(?array $images): Collection
    {
        return collect($images ?? [])
            ->map(function (mixed $image): ?array {
                if (! is_array($image)) {
                    return null;
                }

                if (array_key_exists('room_type', $image)) {
                    return $image;
                }

                foreach ($image as $nestedImage) {
                    if (is_array($nestedImage) && array_key_exists('room_type', $nestedImage)) {
                        return $nestedImage;
                    }
                }

                return null;
            })
            ->filter();
    }
}
