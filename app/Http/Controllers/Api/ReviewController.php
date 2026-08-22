<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Review;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'property_id' => 'required|exists:properties,id',
            'booking_id' => 'nullable|exists:bookings,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'required|string|max:2000',
        ]);

        $eligibleBooking = Booking::query()
            ->where('client_id', $request->user()->id)
            ->where('property_id', $data['property_id'])
            ->where('status', '!=', 'cancelled')
            ->where(function ($query) {
                $query
                    ->where('status', 'completed')
                    ->orWhereDate('check_out', '<=', today());
            })
            ->when($data['booking_id'] ?? null, fn ($query, $bookingId) => $query->whereKey($bookingId))
            ->first();

        if (! $eligibleBooking) {
            return response()->json([
                'message' => 'You can only review properties that you have completed a stay at.',
            ], 403);
        }

        if (Review::query()
            ->where('client_id', $request->user()->id)
            ->where('property_id', $data['property_id'])
            ->where('booking_id', $eligibleBooking->id)
            ->exists()) {
            return response()->json([
                'message' => 'You have already reviewed this stay.',
            ], 422);
        }

        $review = Review::create([
            'property_id' => $data['property_id'],
            'client_id' => $request->user()->id,
            'booking_id' => $eligibleBooking->id,
            'rating' => $data['rating'],
            'comment' => $data['comment'],
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Thanks! Your review has been submitted and will appear once it has been moderated.',
            'review' => $review->load('client'),
        ], 201);
    }
}
