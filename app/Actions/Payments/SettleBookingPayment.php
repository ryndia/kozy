<?php

namespace App\Actions\Payments;

use App\Models\Booking;
use App\Models\Payment;
use App\Notifications\BookingConfirmedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Records a payment as settled and confirms the stay it belongs to.
 *
 * Every route to a paid booking ends here - a browser returning from Stripe, a
 * Stripe webhook, a manual settlement in development - so they cannot drift on
 * what settling means. Those routes race each other routinely, so the write is
 * idempotent and taken under a lock.
 */
class SettleBookingPayment
{
    /**
     * @param  array<string, mixed>|null  $providerPayload  The provider's own notification, kept for audit.
     */
    public function execute(
        Payment $payment,
        string $method,
        ?string $transactionRef = null,
        ?array $providerPayload = null,
    ): Booking {
        $settled = false;

        $booking = DB::transaction(function () use ($method, $payment, $providerPayload, $transactionRef, &$settled): Booking {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());
            $booking = $locked->booking()->lockForUpdate()->firstOrFail();

            if ($locked->isPaid()) {
                $payment->setRawAttributes($locked->getAttributes(), true);

                return $booking;
            }

            $locked->forceFill(array_filter([
                'status' => Payment::STATUS_PAID,
                'method' => $method,
                'transaction_ref' => $transactionRef ?: $locked->transaction_ref,
                'provider_payload' => $providerPayload,
                'paid_at' => now(),
            ], fn ($value): bool => $value !== null))->save();

            // A settled stay is a confirmed stay, unless the guest already
            // cancelled it - a late callback must not resurrect that booking.
            if ($booking->status !== Booking::STATUS_CANCELLED) {
                $booking->forceFill(['status' => Booking::STATUS_CONFIRMED])->save();
            }

            $payment->setRawAttributes($locked->getAttributes(), true);
            $booking->setRelation('payment', $locked);
            $settled = true;

            return $booking;
        });

        // Only on the transition, and only after the transaction commits: a
        // retried callback must not mail the guest their confirmation twice.
        if ($settled) {
            $this->sendConfirmation($booking);
        }

        return $booking;
    }

    /**
     * Mail the guest the details of the stay they just paid for.
     *
     * A mail failure must never undo a settled payment, so it is caught and
     * logged - the stamp is only written once the send is handed off.
     */
    private function sendConfirmation(Booking $booking): void
    {
        $booking->loadMissing(['client', 'property', 'payment']);
        $client = $booking->client;

        if (! $client || $booking->confirmation_sent_at !== null) {
            return;
        }

        try {
            $client->notify(new BookingConfirmedNotification($booking));
            $booking->forceFill(['confirmation_sent_at' => now()])->save();
        } catch (\Throwable $exception) {
            Log::error('Could not send the booking confirmation.', [
                'booking_id' => $booking->getKey(),
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Records an outcome the switch reported as declined or expired. The
     * booking itself is left alone so the guest can try another method.
     *
     * @param  array<string, mixed>|null  $providerPayload
     */
    public function fail(Payment $payment, ?array $providerPayload = null): void
    {
        if ($payment->isPaid()) {
            return;
        }

        $payment->forceFill(array_filter([
            'status' => Payment::STATUS_FAILED,
            'provider_payload' => $providerPayload,
        ], fn ($value): bool => $value !== null))->save();
    }
}
