<?php

namespace App\Actions\Bookings;

use App\Actions\Payments\RefundBookingPayment;
use App\Models\Booking;
use App\Models\Refund;
use App\Models\User;
use App\Notifications\BookingCancelledNotification;
use App\Support\CancellationPolicy;
use App\Support\CancellationQuote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a stay and starts the money moving back.
 *
 * Two ways in, because the rules differ. A guest may drop a reservation only
 * before it starts, and gets exactly what the property's policy says. An admin
 * may cancel anything - a stay already under way, or one long finished - and
 * may override the refund, but has to say why.
 */
class CancelBookingAction
{
    public function __construct(
        private readonly RefundBookingPayment $refunds,
    ) {}

    /**
     * A guest cancelling their own reservation.
     */
    public function execute(Booking $booking, ?User $actor = null, ?string $reason = null): Booking
    {
        $this->guardNotAlreadyCancelled($booking);

        if (! $booking->isCancellable()) {
            throw ValidationException::withMessages([
                'booking' => 'This reservation can no longer be cancelled because the stay has already started.',
            ]);
        }

        return $this->cancel($booking, CancellationPolicy::quote($booking), $actor, $reason);
    }

    /**
     * An admin cancelling on someone's behalf.
     *
     * The refund defaults to whatever the policy says - which is nothing once
     * the stay has started - and can be overridden when the situation warrants
     * it. The reason is recorded either way.
     */
    public function forAdmin(
        Booking $booking,
        User $actor,
        ?string $reason = null,
        ?float $refundAmount = null,
    ): Booking {
        $this->guardNotAlreadyCancelled($booking);

        $quote = CancellationPolicy::quote($booking);

        if ($refundAmount !== null) {
            $quote = $this->override($quote, $refundAmount);
        }

        return $this->cancel($booking, $quote, $actor, $reason);
    }

    /**
     * What a guest would get back, without committing to anything.
     */
    public function quote(Booking $booking): CancellationQuote
    {
        return CancellationPolicy::quote($booking);
    }

    private function cancel(
        Booking $booking,
        CancellationQuote $quote,
        ?User $actor,
        ?string $reason,
    ): Booking {
        $booking = DB::transaction(function () use ($actor, $booking, $reason): Booking {
            $booking->forceFill([
                'status' => Booking::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'cancelled_by_id' => $actor?->getKey(),
            ])->save();

            return $booking->refresh();
        });

        // Outside the transaction: a provider call must not hold a row lock,
        // and a refund that fails still leaves the stay cancelled - the money
        // is then chased from the payout queue rather than lost.
        $refund = $this->refunds->execute($booking, $quote, $actor, $reason);

        $this->notify($booking, $quote, $refund);

        return $booking->refresh()->load(['payment', 'refunds']);
    }

    private function override(CancellationQuote $quote, float $refundAmount): CancellationQuote
    {
        $refund = max(0.0, min(round($refundAmount, 2), $quote->paid));

        return new CancellationQuote(
            paid: $quote->paid,
            fee: round($quote->paid - $refund, 2),
            refund: $refund,
            currency: $quote->currency,
            daysNotice: $quote->daysNotice,
            rule: $quote->rule,
            explanation: 'The refund for this cancellation was set manually by the Kozy team.',
        );
    }

    private function notify(Booking $booking, CancellationQuote $quote, ?Refund $refund): void
    {
        $booking->loadMissing(['client', 'property']);
        $client = $booking->client;

        if (! $client) {
            return;
        }

        try {
            $client->notify(new BookingCancelledNotification($booking, $quote, $refund));
        } catch (\Throwable $exception) {
            // Telling the guest is important, but not so important that failing
            // to do it should un-cancel their booking.
            Log::error('Could not send the cancellation notice.', [
                'booking_id' => $booking->getKey(),
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function guardNotAlreadyCancelled(Booking $booking): void
    {
        if ($booking->status === Booking::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'booking' => 'This reservation has already been cancelled.',
            ]);
        }
    }
}
