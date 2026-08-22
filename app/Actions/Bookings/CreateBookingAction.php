<?php

namespace App\Actions\Bookings;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateBookingAction
{
    /**
     * @param  array{property_id: int|string, check_in: string, check_out: string, guests: int|string}  $data
     * @return array{0: Booking, 1: Payment}
     */
    public function execute(array $data, User $client): array
    {
        $property = Property::query()->findOrFail($data['property_id']);

        if ($property->status !== 'active') {
            throw ValidationException::withMessages([
                'property_id' => 'This property is not accepting reservations right now.',
            ]);
        }

        $checkIn = Carbon::parse($data['check_in'])->startOfDay();
        $checkOut = Carbon::parse($data['check_out'])->startOfDay();
        $nights = (int) $checkIn->diffInDays($checkOut);

        if ($nights < 1) {
            throw ValidationException::withMessages([
                'check_out' => 'The check-out date must be at least one night after the check-in date.',
            ]);
        }

        if ($checkIn->isBefore(today())) {
            throw ValidationException::withMessages([
                'check_in' => 'The check-in date cannot be in the past.',
            ]);
        }

        if ((int) $data['guests'] > (int) $property->max_guests) {
            throw ValidationException::withMessages([
                'guests' => 'The selected guest count exceeds this property\'s capacity.',
            ]);
        }

        return DB::transaction(function () use ($checkIn, $checkOut, $client, $data, $nights, $property): array {
            $overlap = Booking::query()
                ->where('property_id', $property->id)
                ->active()
                ->overlapping($checkIn, $checkOut)
                ->lockForUpdate()
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages([
                    'check_in' => 'Selected dates are not available for this property.',
                ]);
            }

            $total = round($nights * (float) $property->price_per_night, 2);

            $booking = Booking::query()->create([
                'property_id' => $property->id,
                'client_id' => $client->id,
                'check_in' => $checkIn->toDateString(),
                'check_out' => $checkOut->toDateString(),
                'guests' => (int) $data['guests'],
                'status' => Booking::STATUS_PENDING,
                'total_price' => $total,
            ]);

            $payment = Payment::query()->create([
                'booking_id' => $booking->id,
                'amount' => $total,
                'method' => 'stripe',
                'status' => Payment::STATUS_PENDING,
            ]);

            $booking->setRelation('payment', $payment);
            $booking->setRelation('property', $property);

            return [$booking, $payment];
        });
    }
}
