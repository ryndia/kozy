<?php

namespace App\Http\Controllers\Api;

use App\Actions\Payments\SettleBookingPayment;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Payment;
use App\Support\SimulatedCardGateway;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Card payments.
 *
 * For now the card form is simulated (see {@see SimulatedCardGateway}): it
 * accepts test cards only and moves no money, so the whole booking flow - pay,
 * confirm, cancel, refund - works while the MCB gateway is being set up. With
 * card payments disabled, bookings are settled by hand in the back office.
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly SettleBookingPayment $settle,
        private readonly SimulatedCardGateway $gateway,
    ) {}

    /**
     * Pay for a booking with a test card, confirming it on approval.
     */
    public function pay(Request $request): JsonResponse
    {
        $request->validate(['booking_id' => 'required|integer|exists:bookings,id']);

        $booking = $this->payableBooking($request, (int) $request->input('booking_id'));

        if (! SimulatedCardGateway::enabled()) {
            return response()->json([
                'message' => 'Online card payment is not available yet. We will contact you to arrange payment.',
                'card_payments' => false,
            ], 422);
        }

        $data = $request->validate([
            'card_name' => 'required|string|max:100',
            'card_number' => ['required', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail): void {
                if (! SimulatedCardGateway::isTestCard((string) $value)) {
                    $fail('This is a test payment form. Use one of the test cards shown - never a real card.');
                }
            }],
            'card_expiry' => ['required', 'string', 'regex:/^(0[1-9]|1[0-2])\s*\/\s*\d{2}$/', function (string $attribute, mixed $value, Closure $fail): void {
                [$month, $year] = array_map('intval', explode('/', str_replace(' ', '', (string) $value)));

                if (now()->startOfMonth()->greaterThan(now()->setDate(2000 + $year, $month, 1)->startOfMonth())) {
                    $fail('This card has expired.');
                }
            }],
            'card_cvc' => ['required', 'string', 'regex:/^\d{3,4}$/'],
        ], [
            'card_expiry.regex' => 'Enter the expiry date as MM/YY.',
            'card_cvc.regex' => 'The security code is the 3 or 4 digits on the card.',
        ]);

        $payment = $this->paymentFor($booking);
        $result = $this->gateway->charge($data['card_number']);

        // The card itself is never kept - only enough to recognise it on a
        // receipt or in the back office.
        $payload = [
            'simulated' => true,
            'approved' => $result['approved'],
            'brand' => $result['brand'],
            'last4' => $result['last4'],
            'reference' => $result['reference'],
            'message' => $result['message'],
        ];

        if (! $result['approved']) {
            $this->settle->fail($payment, $payload);

            return response()->json([
                'message' => $result['message'],
                'declined' => true,
            ], 422);
        }

        $booking = $this->settle->execute($payment, Payment::METHOD_CARD, $result['reference'], $payload);
        $booking->load(['property.images', 'payment', 'review']);

        return response()->json([
            'message' => 'Payment received. Your reservation is confirmed.',
            'booking' => (new BookingResource($booking))->resolve(),
        ]);
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
                'method' => Payment::METHOD_CARD,
                'status' => Payment::STATUS_PENDING,
            ]);

            $booking->setRelation('payment', $payment);
        }

        return $payment;
    }
}
