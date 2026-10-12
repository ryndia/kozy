<?php

namespace App\Http\Controllers\Api;

use App\Actions\Payments\SettleBookingPayment;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Card payments, through Stripe.
 *
 * The guest pays in a Stripe Payment Element on our own page, which settles the
 * intent directly with Stripe - the card never reaches this server. We then
 * hear about it twice: the browser comes back and calls {@see self::confirm()},
 * and Stripe calls {@see self::webhook()}. Either is enough on its own, which
 * is the point: a guest who closes the tab still gets their booking confirmed.
 *
 * Without Stripe keys the flow falls back to a manual settlement so the booking
 * workflow stays usable in development. Production refuses that.
 */
class PaymentController extends Controller
{
    /**
     * Currencies Stripe expects in whole units rather than hundredths.
     *
     * @var array<int, string>
     */
    private const ZERO_DECIMAL_CURRENCIES = [
        'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga',
        'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
    ];

    public function __construct(private readonly SettleBookingPayment $settle) {}

    /**
     * Start - or pick up - the card payment for a booking.
     */
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

        $currency = strtolower($booking->currency);
        $amount = $this->toMinorUnits((float) $payment->amount, $currency);

        try {
            $intent = $this->resolveIntent($booking, $payment, $amount, $currency);
        } catch (ApiErrorException $exception) {
            return response()->json([
                'message' => 'Stripe could not start this payment: '.$exception->getMessage(),
                'stripe_configured' => true,
            ], 422);
        }

        // An intent that already succeeded means the money is in and we simply
        // had not heard yet - settle rather than asking the guest to pay twice.
        if ($intent->status === PaymentIntent::STATUS_SUCCEEDED) {
            $booking = $this->settle->execute($payment, Payment::METHOD_STRIPE, $intent->id);
            $booking->load(['property.images', 'payment', 'review']);

            return response()->json([
                'already_paid' => true,
                'message' => 'This payment has already gone through. Your reservation is confirmed.',
                'booking' => (new BookingResource($booking))->resolve(),
            ]);
        }

        $payment->forceFill([
            'transaction_ref' => $intent->id,
            'method' => Payment::METHOD_STRIPE,
        ])->save();

        return response()->json([
            'client_secret' => $intent->client_secret,
            'payment_intent_id' => $intent->id,
            'publishable_key' => config('services.stripe.key'),
            'amount' => (float) $payment->amount,
            'currency' => $booking->currency,
            'stripe_configured' => true,
        ]);
    }

    /**
     * Open a Stripe Checkout Session for a booking, rendered on our page by
     * Checkout's embedded payment form.
     *
     * Every parameter marked "Checkout Studio" was configured in Stripe's
     * Checkout Studio and is listed in STRIPE_INTEGRATION_TODO.md - change them
     * there and here together. The booking is linked through metadata, which is
     * how the webhook finds it again once the guest has paid.
     */
    public function createCheckoutSession(Request $request): JsonResponse
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

        $currency = strtolower($booking->currency);

        try {
            $session = $this->startCheckoutSession([
                // Checkout Studio. ui_mode "form" needs stripe-php 21+; older
                // SDKs call the same thing "custom".
                'ui_mode' => 'form',
                'billing_address_collection' => 'auto',
                'phone_number_collection' => ['enabled' => false],
                'automatic_tax' => ['enabled' => false],
                'submit_type' => 'auto',
                'name_collection' => [
                    'individual' => ['enabled' => true],
                ],
                'saved_payment_method_options' => ['payment_method_save' => 'enabled'],
                'integration_identifier' => 'custom_embedded_web_0001',

                // Stripe refuses saved_payment_method_options on a session
                // without a customer, and guests have no Stripe customer yet.
                'customer_creation' => 'always',

                // A stay is a one-off charge, so payment mode - which is also
                // why payment_method_collection (subscriptions only) is absent.
                'mode' => 'payment',

                // Priced from the booking rather than a Stripe Price ID: every
                // stay has its own total, so no fixed catalogue price fits.
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => $currency,
                        'unit_amount' => $this->toMinorUnits((float) $payment->amount, $currency),
                        'product_data' => [
                            'name' => sprintf(
                                '%s · %d night%s',
                                $booking->property->title ?? 'Kozy stay',
                                $booking->nights,
                                $booking->nights === 1 ? '' : 's',
                            ),
                            'description' => sprintf(
                                'Reservation %s, %s to %s',
                                $booking->reference,
                                $booking->check_in->format('j M Y'),
                                $booking->check_out->format('j M Y'),
                            ),
                        ],
                    ],
                ]],
                'client_reference_id' => (string) $booking->getKey(),
                'metadata' => [
                    'booking_id' => (string) $booking->getKey(),
                    'booking_reference' => $booking->reference,
                ],
            ]);
        } catch (ApiErrorException $exception) {
            return response()->json([
                'message' => 'Stripe could not start this payment: '.$exception->getMessage(),
                'stripe_configured' => true,
            ], 422);
        }

        // Held until the session completes; settling then swaps in the
        // PaymentIntent id, which is what refunds are issued against.
        $payment->forceFill([
            'transaction_ref' => $session->id,
            'method' => Payment::METHOD_STRIPE,
        ])->save();

        return response()->json([
            'client_secret' => $session->client_secret,
            'publishable_key' => config('services.stripe.key'),
            'amount' => (float) $payment->amount,
            'currency' => $booking->currency,
            'stripe_configured' => true,
        ]);
    }

    /**
     * Settle a booking payment and confirm the reservation.
     *
     * Called when the browser returns from the Payment Element. The intent must
     * have succeeded on Stripe's side; we never take the client's word for it.
     */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'payment_intent_id' => 'nullable|string',
        ]);

        $booking = $this->payableBooking($request, (int) $data['booking_id']);
        $payment = $this->paymentFor($booking);
        $method = Payment::METHOD_MANUAL;
        $reference = $payment->transaction_ref;

        if ($this->stripeSecret()) {
            $intentId = $data['payment_intent_id'] ?? $payment->transaction_ref;

            if (! $intentId) {
                return response()->json([
                    'message' => 'Start the card payment before confirming it.',
                ], 422);
            }

            try {
                $intent = $this->retrieveIntent($intentId);
            } catch (ApiErrorException $exception) {
                return response()->json([
                    'message' => 'Stripe could not verify this payment: '.$exception->getMessage(),
                ], 422);
            }

            if ($intent->status !== PaymentIntent::STATUS_SUCCEEDED) {
                return response()->json([
                    'message' => 'This payment has not been completed yet.',
                    'payment_status' => $intent->status,
                ], 422);
            }

            if ((string) ($intent->metadata['booking_id'] ?? '') !== (string) $booking->getKey()) {
                // An intent belonging to another booking must never settle this
                // one, however it reached us.
                return response()->json([
                    'message' => 'This payment does not belong to that reservation.',
                ], 422);
            }

            $method = Payment::METHOD_STRIPE;
            $reference = $intent->id;
        } elseif (app()->isProduction()) {
            return response()->json([
                'message' => 'Card payments are not configured on this environment.',
                'stripe_configured' => false,
            ], 422);
        }

        $booking = $this->settle->execute($payment, $method, $reference);
        $booking->load(['property.images', 'payment', 'review']);

        return response()->json([
            'message' => 'Payment recorded. Your reservation is confirmed.',
            'booking' => (new BookingResource($booking))->resolve(),
        ]);
    }

    /**
     * Stripe's own notification that a payment finished.
     *
     * Unauthenticated by nature - it is Stripe calling, not a guest - so the
     * signature is the only thing standing between a stranger and a confirmed
     * booking. Nothing is read out of the body until it verifies.
     */
    public function webhook(Request $request): JsonResponse
    {
        $secret = config('services.stripe.webhook_secret');

        if (! $secret) {
            Log::warning('A Stripe webhook arrived but no signing secret is configured.');

            return response()->json(['message' => 'Webhooks are not configured.'], 400);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret,
            );
        } catch (\UnexpectedValueException|SignatureVerificationException $exception) {
            Log::warning('Rejected a Stripe webhook.', [
                'ip' => $request->ip(),
                'message' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $intent = $event->data->object ?? null;

        if ($intent instanceof Session) {
            return $event->type === 'checkout.session.completed'
                ? $this->checkoutCompleted($intent)
                : response()->json(['message' => 'Ignored.']);
        }

        if (! $intent instanceof PaymentIntent) {
            return response()->json(['message' => 'Ignored.']);
        }

        $payment = Payment::query()
            ->with('booking')
            ->where('transaction_ref', $intent->id)
            ->first();

        if (! $payment) {
            // 200, deliberately: Stripe should stop retrying an intent we will
            // never recognise. The log is where this gets chased.
            Log::warning('Received a Stripe webhook for an unknown payment intent.', [
                'payment_intent' => $intent->id,
            ]);

            return response()->json(['message' => 'Unknown payment intent.']);
        }

        return match ($event->type) {
            'payment_intent.succeeded' => $this->webhookSucceeded($payment, $intent),
            'payment_intent.payment_failed' => $this->webhookFailed($payment, $intent),
            default => response()->json(['message' => 'Ignored.']),
        };
    }

    /**
     * A guest finished paying in the embedded Checkout form.
     *
     * The session is found by the id stored when it was opened, falling back
     * to the booking in its metadata - a guest who reopened the dialog has a
     * newer session on record, but money taken through an older one is still
     * theirs. The event is signature-verified, so the metadata is ours.
     */
    private function checkoutCompleted(Session $session): JsonResponse
    {
        $payment = Payment::query()->with('booking')->where('transaction_ref', $session->id)->first()
            ?? Payment::query()->with('booking')
                ->where('booking_id', (int) ($session->metadata['booking_id'] ?? 0))
                ->first();

        if (! $payment) {
            Log::warning('Received a Stripe webhook for an unknown checkout session.', [
                'checkout_session' => $session->id,
            ]);

            return response()->json(['message' => 'Unknown checkout session.']);
        }

        // Card payments are paid by the time the session completes. A delayed
        // method would report "unpaid" here and settle later.
        if ($session->payment_status !== 'paid') {
            return response()->json(['message' => 'Awaiting payment.']);
        }

        $expected = $this->toMinorUnits((float) $payment->amount, strtolower($payment->currency));

        if ((int) $session->amount_total !== $expected) {
            Log::error('Stripe Checkout reported a total that does not match the payment.', [
                'payment_id' => $payment->getKey(),
                'expected' => $expected,
                'received' => $session->amount_total,
            ]);

            return response()->json(['message' => 'Amount mismatch.'], 422);
        }

        $this->settle->execute(
            $payment,
            Payment::METHOD_STRIPE,
            is_string($session->payment_intent) ? $session->payment_intent : $session->id,
            $session->toArray(),
        );

        return response()->json(['message' => 'Payment recorded.']);
    }

    private function webhookSucceeded(Payment $payment, PaymentIntent $intent): JsonResponse
    {
        // A notification settling the wrong amount settles nothing. Record it
        // and leave the payment open for a human to reconcile.
        $expected = $this->toMinorUnits(
            (float) $payment->amount,
            strtolower($payment->currency),
        );

        if ((int) $intent->amount_received !== $expected) {
            Log::error('Stripe reported a settled amount that does not match the payment.', [
                'payment_id' => $payment->getKey(),
                'expected' => $expected,
                'received' => $intent->amount_received,
            ]);

            return response()->json(['message' => 'Amount mismatch.'], 422);
        }

        $this->settle->execute(
            $payment,
            Payment::METHOD_STRIPE,
            $intent->id,
            $intent->toArray(),
        );

        return response()->json(['message' => 'Payment recorded.']);
    }

    private function webhookFailed(Payment $payment, PaymentIntent $intent): JsonResponse
    {
        if (! $payment->isPaid()) {
            $payment->forceFill([
                'status' => Payment::STATUS_FAILED,
                'provider_payload' => $intent->toArray(),
            ])->save();
        }

        return response()->json(['message' => 'Acknowledged.']);
    }

    /**
     * Reuse the booking's existing intent where we can.
     *
     * Every click of "pay" otherwise leaves another abandoned intent on the
     * Stripe dashboard, and a guest who reopens the dialog should land back on
     * the payment they already started.
     *
     * @throws ApiErrorException
     */
    private function resolveIntent(Booking $booking, Payment $payment, int $amount, string $currency): PaymentIntent
    {
        if ($payment->transaction_ref) {
            try {
                $existing = $this->retrieveIntent($payment->transaction_ref);

                if ($existing->status === PaymentIntent::STATUS_SUCCEEDED) {
                    return $existing;
                }

                if ($existing->status !== PaymentIntent::STATUS_CANCELED) {
                    // The total can change between attempts, so keep the intent
                    // honest rather than charging a stale amount.
                    return $existing->amount !== $amount || $existing->currency !== $currency
                        ? $this->updateIntent($existing->id, $amount, $currency)
                        : $existing;
                }
            } catch (ApiErrorException) {
                // A reference Stripe no longer knows: fall through and mint a
                // fresh intent rather than failing the whole request.
            }
        }

        return $this->createStripeIntent($booking, $amount, $currency);
    }

    /*
     * The three calls that reach Stripe, each on its own so tests can stand in
     * for them without touching the network.
     */

    /** @throws ApiErrorException */
    protected function retrieveIntent(string $intentId): PaymentIntent
    {
        return $this->stripe()->paymentIntents->retrieve($intentId);
    }

    /** @throws ApiErrorException */
    protected function updateIntent(string $intentId, int $amount, string $currency): PaymentIntent
    {
        return $this->stripe()->paymentIntents->update($intentId, [
            'amount' => $amount,
            'currency' => $currency,
        ]);
    }

    /** @throws ApiErrorException */
    protected function createStripeIntent(Booking $booking, int $amount, string $currency): PaymentIntent
    {
        return $this->stripe()->paymentIntents->create([
            'amount' => $amount,
            'currency' => $currency,
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => [
                'booking_id' => (string) $booking->getKey(),
                'booking_reference' => $booking->reference,
            ],
            'description' => sprintf('Kozy stay %s', $booking->reference),
        ], [
            // Guards against a double-click creating two intents for one stay.
            'idempotency_key' => sprintf('booking-%d-%d-%s', $booking->getKey(), $amount, $currency),
        ]);
    }

    /**
     * Checkout's embedded form is in preview, and only answers to this API
     * version with its beta flag - so this client pins it, leaving the rest of
     * the integration on the SDK's default version.
     *
     * @param  array<string, mixed>  $params
     *
     * @throws ApiErrorException
     */
    protected function startCheckoutSession(array $params): Session
    {
        return (new StripeClient([
            'api_key' => $this->stripeSecret(),
            'stripe_version' => '2026-03-25.dahlia; custom_checkout_payment_form_preview=v1',
        ]))->checkout->sessions->create($params);
    }

    /**
     * Stripe takes amounts in the currency's smallest unit, which is not always
     * a hundredth.
     */
    private function toMinorUnits(float $amount, string $currency): int
    {
        return in_array(strtolower($currency), self::ZERO_DECIMAL_CURRENCIES, true)
            ? (int) round($amount)
            : (int) round($amount * 100);
    }

    private function payableBooking(Request $request, int $bookingId): Booking
    {
        $booking = Booking::query()
            ->with(['property', 'payment'])
            ->where('client_id', $request->user()->id)
            ->findOrFail($bookingId);

        abort_if($booking->status === Booking::STATUS_CANCELLED, 422, 'This reservation has been cancelled.');
        abort_if($booking->payment?->isPaid() === true, 422, 'This reservation is already paid.');

        return $booking;
    }

    private function paymentFor(Booking $booking): Payment
    {
        $payment = $booking->payment;

        if (! $payment) {
            $payment = $booking->payment()->create([
                'amount' => $booking->total_price,
                'method' => Payment::METHOD_STRIPE,
                'status' => Payment::STATUS_PENDING,
            ]);

            $booking->setRelation('payment', $payment);
        }

        return $payment;
    }

    private function stripeSecret(): ?string
    {
        return config('services.stripe.secret');
    }

    protected function stripe(): StripeClient
    {
        return new StripeClient($this->stripeSecret());
    }
}
