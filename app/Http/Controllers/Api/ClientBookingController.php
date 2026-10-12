<?php

namespace App\Http\Controllers\Api;

use App\Actions\Bookings\CancelBookingAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ClientBookingController extends Controller
{
    public function __construct(
        protected CancelBookingAction $cancelBooking,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'stage' => ['nullable', 'string', 'in:upcoming,staying,past,cancelled'],
        ]);

        $bookings = $this->bookingsFor($request)
            ->orderByDesc('check_in')
            ->get();

        $visible = filled($filters['stage'] ?? null)
            ? $bookings->filter(fn (Booking $booking): bool => $booking->stage() === $filters['stage'])->values()
            : $bookings;

        return response()->json([
            'bookings' => BookingResource::collection($visible)->resolve(),
            'summary' => $this->summarize($bookings),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $booking = $this->bookingsFor($request)->findOrFail($id);

        return response()->json([
            'booking' => (new BookingResource($booking))->resolve(),
        ]);
    }

    /**
     * What cancelling would cost, so the guest sees it before they commit.
     */
    public function cancellationQuote(Request $request, int $id): JsonResponse
    {
        $booking = $this->bookingsFor($request)->findOrFail($id);

        return response()->json([
            'quote' => $this->cancelBooking->quote($booking)->toArray(),
            'can_cancel' => $booking->isCancellable(),
        ]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $booking = $this->bookingsFor($request)->findOrFail($id);

        // Quoted before the cancellation, because afterwards there is nothing
        // left to quote - the guest is told exactly what was applied.
        $quote = $this->cancelBooking->quote($booking);

        $this->cancelBooking->execute($booking, $request->user(), $data['reason'] ?? null);

        $booking->load(['property.images', 'payment', 'review', 'refunds']);

        return response()->json([
            'message' => $quote->hasRefund()
                ? sprintf(
                    'Your reservation has been cancelled. %s %s will be refunded.',
                    $quote->currency,
                    number_format($quote->refund, 2),
                )
                : 'Your reservation has been cancelled.',
            'quote' => $quote->toArray(),
            'booking' => (new BookingResource($booking))->resolve(),
        ]);
    }

    public function receipt(Request $request, int $id): Response
    {
        $booking = Booking::query()
            ->with(['property', 'payment', 'client'])
            ->where('client_id', $request->user()->id)
            ->findOrFail($id);

        $pdf = Pdf::loadView('receipts.booking', [
            'booking' => $booking,
        ]);

        return $pdf->download("kozy-booking-{$booking->id}.pdf");
    }

    /**
     * @return Builder<Booking>
     */
    protected function bookingsFor(Request $request)
    {
        return Booking::query()
            ->with(['property.images', 'payment', 'review', 'refunds'])
            ->where('client_id', $request->user()->id);
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return array<string, int|float>
     */
    protected function summarize(Collection $bookings): array
    {
        $active = $bookings->reject(fn (Booking $booking): bool => $booking->status === Booking::STATUS_CANCELLED);

        return [
            'total' => $bookings->count(),
            'upcoming' => $bookings->filter(fn (Booking $booking): bool => $booking->stage() === 'upcoming')->count(),
            'staying' => $bookings->filter(fn (Booking $booking): bool => $booking->stage() === 'staying')->count(),
            'past' => $bookings->filter(fn (Booking $booking): bool => $booking->stage() === 'past')->count(),
            'cancelled' => $bookings->filter(fn (Booking $booking): bool => $booking->stage() === 'cancelled')->count(),
            'pending_payments' => $active->filter(fn (Booking $booking): bool => $booking->payment?->status !== Payment::STATUS_PAID)->count(),
            'reviews_due' => $bookings->filter(fn (Booking $booking): bool => $booking->isReviewable())->count(),
            'amount_paid' => round((float) $active
                ->filter(fn (Booking $booking): bool => $booking->payment?->status === Payment::STATUS_PAID)
                ->sum(fn (Booking $booking): float => (float) $booking->total_price), 2),
            'amount_due' => round((float) $active
                ->filter(fn (Booking $booking): bool => $booking->payment?->status !== Payment::STATUS_PAID)
                ->sum(fn (Booking $booking): float => (float) $booking->total_price), 2),
        ];
    }
}
