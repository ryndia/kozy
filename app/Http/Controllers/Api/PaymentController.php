<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class PaymentController extends Controller
{
    public function createIntent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'booking_id' => 'required|exists:bookings,id',
        ]);

        $booking = $this->payableBooking($request, (int) $data['booking_id']);
        $payment = $this->paymentFor($booking);

        if (! $this->stripeSecret()) {
            return response()->json([
                'message' => 'Card payments are not configured on this environment.',
                'stripe_configured' => false,
            ], 422);
        }

        try {
            $intent = $this->stripe()->paymentIntents->create([
                'amount' => (int) round((float) $booking->total_price * 100),
                'currency' => strtolower($booking->property?->currency ?: 'usd'),
                'metadata' => ['booking_id' => $booking->id],
            ]);
        } catch (ApiErrorException $exception) {
            return response()->json([
                'message' => 'Stripe could not start this payment: '.$exception->getMessage(),
                'stripe_configured' => true,
            ], 422);
        }

        $payment->forceFill([
            'transaction_ref' => $intent->id,
            'method' => 'stripe',
        ])->save();

        return response()->json([
            'client_secret' => $intent->client_secret,
            'payment_intent_id' => $intent->id,
            'stripe_configured' => true,
        ]);
    }

    /**
     * Settle a booking payment and confirm the reservation.
     *
     * With Stripe configured the intent must have succeeded on Stripe's side.
     * Without Stripe keys the payment is recorded as a manual settlement so the
     * booking workflow stays usable outside production.
     */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'payment_intent_id' => 'nullable|string',
        ]);

        $booking = $this->payableBooking($request, (int) $data['booking_id']);
        $payment = $this->paymentFor($booking);
        $method = 'manual';
        $reference = $payment->transaction_ref;

        if ($this->stripeSecret()) {
            $intentId = $data['payment_intent_id'] ?? $payment->transaction_ref;

            if (! $intentId) {
                return response()->json([
                    'message' => 'Start the card payment before confirming it.',
                ], 422);
            }

            try {
                $intent = $this->stripe()->paymentIntents->retrieve($intentId);
            } catch (ApiErrorException $exception) {
                return response()->json([
                    'message' => 'Stripe could not verify this payment: '.$exception->getMessage(),
                ], 422);
            }

            if ($intent->status !== 'succeeded') {
                return response()->json([
                    'message' => 'This payment has not been completed yet.',
                    'payment_status' => $intent->status,
                ], 422);
            }

            $method = 'stripe';
            $reference = $intent->id;
        } elseif (app()->isProduction()) {
            return response()->json([
                'message' => 'Card payments are not configured on this environment.',
                'stripe_configured' => false,
            ], 422);
        }

        $payment->forceFill([
            'status' => Payment::STATUS_PAID,
            'method' => $method,
            'transaction_ref' => $reference,
            'paid_at' => now(),
        ])->save();

        $booking->forceFill(['status' => Booking::STATUS_CONFIRMED])->save();
        $booking->load(['property.images', 'payment', 'review']);

        return response()->json([
            'message' => 'Payment recorded. Your reservation is confirmed.',
            'booking' => (new BookingResource($booking))->resolve(),
        ]);
    }

    protected function payableBooking(Request $request, int $bookingId): Booking
    {
        $booking = Booking::query()
            ->with(['property', 'payment'])
            ->where('client_id', $request->user()->id)
            ->findOrFail($bookingId);

        abort_if($booking->status === Booking::STATUS_CANCELLED, 422, 'This reservation has been cancelled.');
        abort_if($booking->payment?->status === Payment::STATUS_PAID, 422, 'This reservation is already paid.');

        return $booking;
    }

    protected function paymentFor(Booking $booking): Payment
    {
        $payment = $booking->payment;

        if (! $payment) {
            $payment = $booking->payment()->create([
                'amount' => $booking->total_price,
                'method' => 'stripe',
                'status' => Payment::STATUS_PENDING,
            ]);

            $booking->setRelation('payment', $payment);
        }

        return $payment;
    }

    protected function stripeSecret(): ?string
    {
        return config('services.stripe.secret');
    }

    protected function stripe(): StripeClient
    {
        return new StripeClient($this->stripeSecret());
    }
}
