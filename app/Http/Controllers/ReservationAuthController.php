<?php

namespace App\Http\Controllers;

use App\Actions\Bookings\CreateBookingAction;
use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\RenterOtpNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

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

        $token = (string) Str::uuid();
        $otp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put("reservation-registration:{$token}", [
            'otp' => $otp,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'booking' => [
                'property_id' => (int) $data['property_id'],
                'check_in' => $data['check_in'],
                'check_out' => $data['check_out'],
                'guests' => (int) $data['guests'],
            ],
        ], now()->addMinutes(10));

        Notification::route('mail', $data['email'])->notify(new RenterOtpNotification($otp));

        return response()->json([
            'message' => 'A verification code has been sent to your email address.',
            'email' => $data['email'],
            'token' => $token,
        ], 201);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'otp' => ['required', 'digits:6'],
        ]);

        $pendingRegistration = Cache::get("reservation-registration:{$data['token']}");

        if (! is_array($pendingRegistration)) {
            throw ValidationException::withMessages([
                'otp' => 'This verification request has expired. Please start again.',
            ]);
        }

        if (($pendingRegistration['otp'] ?? null) !== $data['otp']) {
            throw ValidationException::withMessages([
                'otp' => 'The verification code is invalid.',
            ]);
        }

        if (User::query()->where('email', $pendingRegistration['email'])->exists()) {
            Cache::forget("reservation-registration:{$data['token']}");

            throw ValidationException::withMessages([
                'email' => 'An account with this email already exists. Please log in to continue.',
            ]);
        }

        $user = User::query()->create([
            'name' => $pendingRegistration['name'],
            'email' => $pendingRegistration['email'],
            'password' => $pendingRegistration['password'],
            'role' => UserRole::USER->value,
            'otp_verified_at' => now(),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        [$booking, $payment] = $this->createBooking->execute($pendingRegistration['booking'], $user);

        Cache::forget("reservation-registration:{$data['token']}");

        return response()->json([
            'message' => 'Your account is ready and the reservation has been created.',
            'user' => $user,
            'booking' => $booking->load(['property', 'payment']),
            'payment' => $payment,
            'redirect_url' => url('/client/bookings'),
        ]);
    }
}
