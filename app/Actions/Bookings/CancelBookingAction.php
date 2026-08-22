<?php

namespace App\Actions\Bookings;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelBookingAction
{
    public function execute(Booking $booking): Booking
    {
        if ($booking->status === Booking::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'booking' => 'This reservation has already been cancelled.',
            ]);
        }

        if (! $booking->isCancellable()) {
            throw ValidationException::withMessages([
                'booking' => 'This reservation can no longer be cancelled because the stay has already started.',
            ]);
        }

        return DB::transaction(function () use ($booking): Booking {
            $booking->forceFill(['status' => Booking::STATUS_CANCELLED])->save();

            $payment = $booking->payment;

            if ($payment && $payment->status === Payment::STATUS_PAID) {
                $payment->forceFill(['status' => Payment::STATUS_REFUNDED])->save();
            }

            return $booking->refresh();
        });
    }
}
