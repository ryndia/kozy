<?php

namespace App\Http\Resources;

use App\Models\Booking;
use App\Support\Pricing;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Booking
 */
class BookingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
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
            'vat' => Pricing::vatBreakdown((float) $this->total_price),
            'currency' => $property->currency ?? 'USD',
            'created_at' => $this->created_at?->toIso8601String(),
            'can_cancel' => $this->isCancellable(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'refunds' => $this->relationLoaded('refunds')
                ? $this->refunds->map(fn ($refund): array => [
                    'id' => $refund->id,
                    'amount' => (float) $refund->amount,
                    'fee_amount' => (float) $refund->fee_amount,
                    'currency' => $refund->currency,
                    'status' => $refund->status,
                    'processed_at' => $refund->processed_at?->toIso8601String(),
                ])->values()
                : null,
            'can_pay' => $this->isPayable(),
            'can_review' => $this->isReviewable(),
            'receipt_url' => url("/api/client/bookings/{$this->id}/receipt"),
            'payment' => $payment ? [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'method' => $payment->method,
                'status' => $payment->status,
                'refunded_amount' => (float) $payment->refunded_amount,
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
                'terms_and_conditions' => $property->terms(),
                'cancellation_policy' => $property->cancellationPolicy(),
                'cancellation_fee_percent' => $property->cancellationFeePercent(),
                'image' => $property->relationLoaded('images') && $property->images->first()
                    ? url('storage/'.$property->images->first()->image_path)
                    : null,
            ] : null,
        ];
    }
}
