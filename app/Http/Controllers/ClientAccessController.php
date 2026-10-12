<?php

namespace App\Http\Controllers;

use App\Actions\Bookings\CreateBookingAction;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\AuthRedirect;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sign-up flow for guests: a name and an email are enough to reserve. The
 * account and the booking are created there and then, the guest goes straight
 * to paying for the room, and chooses a password once they have paid. Guests
 * who already have an account authenticate with their password instead - this
 * flow never overwrites the credentials of an existing account.
 *
 * There is no email verification code. The payment, and the confirmation mail
 * that follows it, are what show the address is real.
 */
class ClientAccessController extends Controller
{
    protected const SESSION_KEY = 'client_auth_flow';

    public function __construct(
        protected CreateBookingAction $createBooking,
    ) {}

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'intended' => ['nullable', 'string', 'max:255'],
            'reservation' => ['nullable', 'array'],
            'reservation.property_id' => ['required_with:reservation', 'exists:properties,id'],
            'reservation.check_in' => ['required_with:reservation', 'date', 'after_or_equal:today'],
            'reservation.check_out' => ['required_with:reservation', 'date', 'after:reservation.check_in'],
            'reservation.guests' => ['required_with:reservation', 'integer', 'min:1'],
        ]);

        $email = Str::lower($data['email']);
        $intended = $this->normalizeIntended($data['intended'] ?? null);
        $reservation = $data['reservation'] ?? null;

        // An email that already has an account signs in with its password. The
        // reservation is parked in the session so it survives the password step.
        if (User::query()->where('email', $email)->exists()) {
            $request->session()->put(self::SESSION_KEY, [
                'email' => $email,
                'intended' => $intended,
                'reservation' => $reservation,
                'verified' => false,
            ]);

            return response()->json([
                'account_exists' => true,
                'email' => $email,
                'message' => 'This email already has an account. Enter your password to continue.',
            ]);
        }

        // A new guest gets an account straight away, holding a random
        // placeholder password until they choose one after paying. The account
        // and the booking stand or fall together: dates that turn out to be
        // taken leave no half-made account behind.
        try {
            [$user, $booking] = DB::transaction(function () use ($data, $email, $reservation): array {
                $user = User::query()->create([
                    'name' => $data['name'],
                    'email' => $email,
                    'password' => Str::random(64),
                    'must_set_password' => true,
                    'role' => UserRole::USER->value,
                    'otp_verified_at' => now(),
                ]);

                $booking = is_array($reservation)
                    ? $this->createBooking->execute($reservation, $user)[0]
                    : null;

                return [$user, $booking];
            });
        } catch (UniqueConstraintViolationException) {
            // A double submit raced us to the same email. The account exists
            // now, so treat this exactly like an existing email.
            throw ValidationException::withMessages([
                'email' => 'This email already has an account. Please log in to continue.',
            ]);
        }

        Auth::login($user, true);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, ['intended' => $intended]);

        // With a stay to pay for, the guest lands on its payment; otherwise
        // there is nothing to pay and they choose their password now.
        return response()->json([
            'account_exists' => false,
            'email' => $email,
            'booking_id' => $booking?->getKey(),
            'message' => $booking
                ? 'Your dates are reserved. Complete the payment to confirm them.'
                : 'Choose a password to finish creating your account.',
            'redirect_url' => $booking
                ? route('client.bookings', ['pay' => $booking->getKey()], absolute: false)
                : route('client.auth.password', absolute: false),
        ]);
    }

    /**
     * Authenticate an existing client with their password, finishing any
     * reservation that was started before we knew the account existed.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
            'intended' => ['nullable', 'string', 'max:255'],
            'reservation' => ['nullable', 'array'],
            'reservation.property_id' => ['required_with:reservation', 'exists:properties,id'],
            'reservation.check_in' => ['required_with:reservation', 'date', 'after_or_equal:today'],
            'reservation.check_out' => ['required_with:reservation', 'date', 'after:reservation.check_in'],
            'reservation.guests' => ['required_with:reservation', 'integer', 'min:1'],
        ]);

        $email = Str::lower($data['email']);
        $throttleKey = Str::transliterate($email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Please try again in '.RateLimiter::availableIn($throttleKey).' seconds.',
            ]);
        }

        if (! Auth::attempt(['email' => $email, 'password' => $data['password']], (bool) ($data['remember'] ?? false))) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'password' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        $flow = $this->getFlow($request) ?? [];
        $reservation = $data['reservation'] ?? ($flow['reservation'] ?? null);
        $redirectUrl = $this->normalizeIntended($data['intended'] ?? ($flow['intended'] ?? null), $request->user());

        if (is_array($reservation)) {
            $this->createBooking->execute($reservation, $request->user());
            $redirectUrl = route('client.bookings', absolute: false);
        }

        $request->session()->forget(self::SESSION_KEY);

        return response()->json([
            'message' => 'Welcome back.',
            'redirect_url' => $redirectUrl,
        ]);
    }

    /**
     * Where a guest who paid first chooses their password.
     */
    public function showPassword(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->must_set_password) {
            return redirect()->route('client.bookings');
        }

        return Inertia::render('auth/set-password', [
            'email' => $user->email,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function storePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $request->user();

        // Only the placeholder may be replaced here. Changing a real password
        // belongs to the settings page, which asks for the current one first.
        if (! $user || ! $user->must_set_password) {
            throw ValidationException::withMessages([
                'password' => 'Your password is already set. Change it from your account settings instead.',
            ]);
        }

        $user->forceFill([
            'password' => $data['password'],
            'must_set_password' => false,
        ])->save();

        $redirectUrl = $this->normalizeIntended($this->getFlow($request)['intended'] ?? null, $user);
        $request->session()->forget(self::SESSION_KEY);

        return response()->json([
            'redirect_url' => $redirectUrl,
        ]);
    }

    protected function getFlow(Request $request): ?array
    {
        $flow = $request->session()->get(self::SESSION_KEY);

        return is_array($flow) ? $flow : null;
    }

    /**
     * Where to send the browser next. Admins signing in through this flow belong
     * in the back office, everyone else in their bookings.
     */
    protected function normalizeIntended(?string $intended, ?User $user = null): string
    {
        if (is_string($intended) && str_starts_with($intended, '/') && ! str_starts_with($intended, '//')) {
            return $intended;
        }

        if ($user?->isAdmin()) {
            return AuthRedirect::panelUrl();
        }

        return route('client.bookings', absolute: false);
    }
}
