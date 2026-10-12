<?php

namespace App\Actions\Payments;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Support\CancellationQuote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Puts a cancellation's refund into motion.
 *
 * The record comes first and always: the amount is decided at cancellation,
 * whether or not the money can move in the same breath. A card payment is
 * refunded through Stripe's API there and then. Anything settled another way -
 * cash, a bank transfer, a manual entry - has no API to call, so those rows
 * stay pending and surface in the back office as a payout queue to clear.
 */
class RefundBookingPayment
{
    /**
     * @return Refund|null Null when the cancellation leaves nothing to return.
     */
    public function execute(
        Booking $booking,
        CancellationQuote $quote,
        ?User $actor = null,
        ?string $reason = null,
    ): ?Refund {
        $payment = $booking->payment;

        if (! $quote->hasRefund() || ! $payment) {
            return null;
        }

        $refund = $this->record($booking, $payment, $quote, $actor, $reason);

        // Nothing else can be done automatically; an admin clears the queue.
        if ($payment->method !== Payment::METHOD_STRIPE || ! $this->stripeSecret()) {
            return $refund;
        }

        return $this->sendToStripe($refund, $payment);
    }

    /**
     * Mark a queued refund as paid - the back-office action for money returned
     * by hand, such as a bank transfer.
     */
    public function markCompleted(Refund $refund, ?string $providerReference = null): Refund
    {
        if ($refund->isCompleted()) {
            return $refund;
        }

        return DB::transaction(function () use ($providerReference, $refund): Refund {
            $refund->forceFill([
                'status' => Refund::STATUS_COMPLETED,
                'provider_reference' => $providerReference ?: $refund->provider_reference,
                'processed_at' => now(),
                'failure_reason' => null,
            ])->save();

            $this->applyToPayment($refund);

            return $refund;
        });
    }

    /**
     * Write the refund down and hold back the amount against the payment, so a
     * second cancellation cannot return the same money twice.
     */
    private function record(
        Booking $booking,
        Payment $payment,
        CancellationQuote $quote,
        ?User $actor,
        ?string $reason,
    ): Refund {
        return DB::transaction(fn (): Refund => Refund::query()->create([
            'booking_id' => $booking->getKey(),
            'payment_id' => $payment->getKey(),
            'amount' => $quote->refund,
            'fee_amount' => $quote->fee,
            'currency' => $quote->currency,
            'status' => Refund::STATUS_PENDING,
            'method' => $payment->method,
            'reason' => $reason ?: $quote->explanation,
            'requested_by_id' => $actor?->getKey(),
        ]));
    }

    private function sendToStripe(Refund $refund, Payment $payment): Refund
    {
        $intent = $payment->transaction_ref;

        if (! $intent) {
            return $this->fail($refund, 'This payment has no Stripe reference to refund against.');
        }

        try {
            $reference = $this->createStripeRefund(
                $intent,
                (int) round((float) $refund->amount * 100),
            );
        } catch (ApiErrorException $exception) {
            // A refund Stripe would not take is still owed to the guest, so the
            // row stays and an admin is told why it needs chasing.
            return $this->fail($refund, $exception->getMessage());
        }

        return DB::transaction(function () use ($reference, $refund): Refund {
            $refund->forceFill([
                'status' => Refund::STATUS_COMPLETED,
                'provider_reference' => $reference,
                'processed_at' => now(),
            ])->save();

            $this->applyToPayment($refund);

            return $refund;
        });
    }

    /**
     * The one call that reaches Stripe, kept on its own so tests can stand in
     * for it without reaching the network.
     *
     * @throws ApiErrorException
     */
    protected function createStripeRefund(string $paymentIntentId, int $amountInCents): string
    {
        return (new StripeClient($this->stripeSecret()))->refunds->create([
            'payment_intent' => $paymentIntentId,
            'amount' => $amountInCents,
        ])->id;
    }

    private function fail(Refund $refund, string $message): Refund
    {
        Log::error('A booking refund could not be sent to the provider.', [
            'refund_id' => $refund->getKey(),
            'message' => $message,
        ]);

        $refund->forceFill([
            'status' => Refund::STATUS_FAILED,
            'failure_reason' => $message,
        ])->save();

        return $refund;
    }

    /**
     * Roll the refund into the payment's running total. A payment is only
     * "refunded" once all of it is back; until then it is still a paid payment
     * that happens to be partly returned.
     */
    private function applyToPayment(Refund $refund): void
    {
        $payment = $refund->payment;

        if (! $payment) {
            return;
        }

        /** @var Payment $locked */
        $locked = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

        $refunded = round(
            (float) $locked->refunds()->where('status', Refund::STATUS_COMPLETED)->sum('amount'),
            2,
        );

        $locked->forceFill([
            'refunded_amount' => $refunded,
            'status' => $refunded >= (float) $locked->amount
                ? Payment::STATUS_REFUNDED
                : $locked->status,
        ])->save();

        $payment->setRawAttributes($locked->getAttributes(), true);
    }

    private function stripeSecret(): ?string
    {
        return config('services.stripe.secret');
    }
}
