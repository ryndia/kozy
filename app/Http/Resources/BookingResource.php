<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        $property = $this->property;
        $payment = $this->payment;
        $review = $this->review;

        return [
            'id' => $this->id,
            'check_in' => $this->check_in?->toDateString(),
            'check_out' => $this->check_out?->toDateString(),
            'nights' => $this->nights,
            'guests' => $this->guests,
            'status' => $this->status,
            'stage' => $this->stage(),
            'total_price' => (float) $this->total_price,
            'currency' => $property?->currency ?? 'USD',
            'created_at' => $this->created_at?->toIso8601String(),
            'can_cancel' => $this->isCancellable(),
            'can_pay' => $this->isPayable(),
            'can_review' => $this->isReviewable(),
            'receipt_url' => url("/api/client/bookings/{$this->id}/receipt"),
            'payment' => $payment ? [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'method' => $payment->method,
                'status' => $payment->status,
                'transaction_ref' => $payment->transaction_ref,
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ] : null,
            'review' => $review ? [
                'id' => $review->id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'status' => $review->status,
                'created_at' => $review->created_at?->toIso8601String(),
            ] : null,
            'property' => $property ? [
                'id' => $property->id,
                'title' => $property->title,
                'slug' => $property->slug,
                'address' => $property->address,
                'currency' => $property->currency ?? 'USD',
                'price_per_night' => (float) $property->price_per_night,
                'check_in_time' => $property->check_in,
                'check_out_time' => $property->check_out,
                'image' => $property->relationLoaded('images') && $property->images->first()
                    ? url('storage/'.$property->images->first()->image_path)
                    : null,
            ] : null,
        ];
    }
}
