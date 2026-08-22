<?php

namespace App\Http\Controllers\Api;

use App\Actions\Bookings\CreateBookingAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(
        protected CreateBookingAction $createBooking,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'property_id' => 'required|exists:properties,id',
            'check_in' => 'required|date|after_or_equal:today',
            'check_out' => 'required|date|after:check_in',
            'guests' => 'required|integer|min:1',
        ]);

        [$booking, $payment] = $this->createBooking->execute($data, $request->user());

        $booking->load(['property.images', 'payment', 'review']);

        return response()->json([
            'booking' => new BookingResource($booking),
            'payment' => $payment,
            'redirect_url' => route('client.bookings', absolute: false),
        ], 201);
    }
}
