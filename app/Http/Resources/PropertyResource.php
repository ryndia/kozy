<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PropertyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'address' => $this->address,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'check_in' => $this->check_in ? $this->check_in : null,
            'check_out' => $this->check_out ? $this->check_out : null,
            'price_per_night' => $this->price_per_night,
            'currency' => $this->currency ?? 'USD',
            'max_guests' => $this->max_guests,
            'bedrooms' => $this->bedrooms,
            'kitchens' => $this->kitchens,
            'bathrooms' => $this->bathrooms,
            'amenities' => $this->amenities,
            'features' => $this->features,
            'status' => $this->status,
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => url('storage/'.$image->image_path),
                'image_path' => $image->image_path,
                'room_type' => $image->room_type,
                'room_name' => $image->room_name,
                'alt_text' => $image->alt_text,
                'order' => $image->order,
                'is_cover' => (bool) $image->is_cover,
            ])->values()),
            'average_rating' => $this->whenLoaded('reviews') ? round($this->reviews->avg('rating'), 2) : null,
            'reviews' => $this->whenLoaded('reviews', fn () => $this->reviews->map(fn ($review) => [
                'id' => $review->id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'status' => $review->status,
                'created_at' => $review->created_at?->toIso8601String(),
                'client' => $review->client ? [
                    'id' => $review->client->id,
                    'name' => $review->client->name,
                ] : null,
            ])->values()),
        ];
    }
}
