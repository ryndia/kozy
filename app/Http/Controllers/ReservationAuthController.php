<?php

namespace App\Http\Controllers;

use App\Actions\Bookings\CreateBookingAction;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/**
 * Create an account and its reservation in one request, straight from a
 * property page.
 *
 * There is no email verification code: the guest goes on to pay for the stay,
 * and the payment - with the confirmation mail that follows - is what proves
 * the address. Holding a booking behind a six digit code only lost reservations
 * to unchecked inboxes.
 */
class ReservationAuthController extends Controller
{
    public function __construct(
        protected CreateBookingAction $createBooking,
    ) {}

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'property_id' => ['required', 'exists:properties,id'],
            'check_in' => ['required', 'date', 'after_or_equal:today'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'guests' => ['required', 'integer', 'min:1'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => UserRole::USER->value,
            // Nothing in the guest flow asks for a code any more, so the stamp
            // is set on creation to keep the renter OTP guard satisfied.
            'otp_verified_at' => now(),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        [$booking, $payment] = $this->createBooking->execute([
            'property_id' => (int) $data['property_id'],
            'check_in' => $data['check_in'],
            'check_out' => $data['check_out'],
            'guests' => (int) $data['guests'],
        ], $user);

        return response()->json([
            'message' => 'Your account is ready and the reservation has been created.',
            'user' => $user,
            'booking' => $booking->load(['property', 'payment']),
            'payment' => $payment,
            'redirect_url' => url('/client/bookings'),
        ], 201);
    }
}
