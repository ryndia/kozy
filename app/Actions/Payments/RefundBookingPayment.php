<?php

namespace App\Actions\Payments;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Support\CancellationQuote;
use App\Support\SimulatedCardGateway;
use Illuminate\Support\Facades\DB;

/**
 * Puts a cancellation's refund into motion.
 *
 * The record comes first and always: the amount is decided at cancellation,
 * whether or not the money can move in the same breath. A card payment is
 * refunded through the card gateway there and then - for now the simulated
 * one, until MCB is integrated. Anything settled another way - cash, a bank
 * transfer, a manual entry - has no API to call, so those rows stay pending
 * and surface in the back office as a payout queue to clear.
 */
class RefundBookingPayment
{
    public function __construct(private readonly SimulatedCardGateway $gateway) {}

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
        if (! SimulatedCardGateway::enabled() || ! $this->gateway->tookPayment($payment)) {
            return $refund;
        }

        return $this->sendToGateway($refund, $payment);
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

    private function sendToGateway(Refund $refund, Payment $payment): Refund
    {
        $reference = $this->gateway->refund($payment, (float) $refund->amount);

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
}
