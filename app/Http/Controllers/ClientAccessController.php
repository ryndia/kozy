<?php

namespace App\Http\Controllers;

use App\Actions\Bookings\CreateBookingAction;
use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\RenterOtpNotification;
use App\Support\AuthRedirect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sign-up flow for guests: verify the email with a one-time code, then set a
 * password. Guests who already have an account authenticate with their password
 * instead - this flow never overwrites the credentials of an existing account.
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

        $code = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        $request->session()->put(self::SESSION_KEY, [
            'name' => $data['name'],
            'email' => $email,
            'otp' => $code,
            'expires_at' => now()->addMinutes(10)->timestamp,
            'verified' => false,
            'intended' => $intended,
            'reservation' => $reservation,
        ]);

        Notification::route('mail', $email)->notify(new RenterOtpNotification($code));

        return response()->json([
            'account_exists' => false,
            'message' => 'A verification code has been sent to your email.',
            'email' => $email,
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

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'otp' => ['required', 'digits:6'],
        ]);

        $flow = $this->getFlow($request);

        if (! $flow || ! ($flow['otp'] ?? null)) {
            throw ValidationException::withMessages([
                'otp' => 'Your verification session has expired. Please start again.',
            ]);
        }

        if (now()->timestamp > (int) ($flow['expires_at'] ?? 0)) {
            $request->session()->forget(self::SESSION_KEY);

            throw ValidationException::withMessages([
                'otp' => 'Your verification code has expired. Please request a new one.',
            ]);
        }

        if (! hash_equals((string) $flow['otp'], $data['otp'])) {
            throw ValidationException::withMessages([
                'otp' => 'The verification code is invalid.',
            ]);
        }

        $flow['verified'] = true;
        $request->session()->put(self::SESSION_KEY, $flow);

        return response()->json([
            'redirect_url' => route('client.auth.password', absolute: false),
        ]);
    }

    public function showPassword(Request $request): Response|RedirectResponse
    {
        $flow = $this->getVerifiedFlow($request);

        if (! $flow) {
            return redirect()->route('login');
        }

        return Inertia::render('auth/set-password', [
            'email' => $flow['email'],
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function storePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $flow = $this->getVerifiedFlow($request);

        if (! $flow) {
            throw ValidationException::withMessages([
                'password' => 'Your verification session has expired. Please start again.',
            ]);
        }

        if (User::query()->where('email', $flow['email'])->exists()) {
            $request->session()->forget(self::SESSION_KEY);

            throw ValidationException::withMessages([
                'password' => 'An account already exists for this email. Please log in with your password instead.',
            ]);
        }

        $user = User::query()->create([
            'name' => $flow['name'],
            'email' => $flow['email'],
            'password' => $data['password'],
            'role' => UserRole::USER->value,
            'otp_verified_at' => now(),
        ]);

        Auth::login($user, true);
        $request->session()->regenerate();

        $redirectUrl = $this->normalizeIntended($flow['intended'] ?? null, $user);

        if (is_array($flow['reservation'] ?? null)) {
            $this->createBooking->execute($flow['reservation'], $user);
            $redirectUrl = route('client.bookings', absolute: false);
        }

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

    protected function getVerifiedFlow(Request $request): ?array
    {
        $flow = $this->getFlow($request);

        if (! $flow || ! ($flow['verified'] ?? false)) {
            return null;
        }

        return $flow;
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
