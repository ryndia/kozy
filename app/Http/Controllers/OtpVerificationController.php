<?php

namespace App\Http\Controllers;

use App\Support\AuthRedirect;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class OtpVerificationController extends Controller
{
    public function show(Request $request): InertiaResponse|Response
    {
        $user = $request->user();

        if (! $user) {
            return Redirect::route('login');
        }

        if (! $user->requiresOtpVerification()) {
            return AuthRedirect::response($request, $user);
        }

        return Inertia::render('auth/otp-verify', [
            'email' => $user->email,
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request): Response
    {
        $request->validate([
            'otp' => ['required', 'digits:6'],
        ]);

        $user = $request->user();

        if (! $user || ! $user->isRenter()) {
            return Redirect::route('login');
        }

        $sessionOtp = (string) $request->session()->get('otp_code');
        $sessionExpiresAt = $request->session()->get('otp_expires_at');

        if (! $sessionOtp || ! $sessionExpiresAt || now()->timestamp > (int) $sessionExpiresAt) {
            return back()->withErrors(['otp' => 'Your OTP has expired. Please log in again to request a new code.']);
        }

        if ((string) $request->input('otp') !== $sessionOtp) {
            return back()->withErrors(['otp' => 'The OTP code is invalid.']);
        }

        $user->forceFill(['otp_verified_at' => now()])->save();
        $request->session()->forget(['otp_code', 'otp_expires_at', 'otp_user_id']);

        return AuthRedirect::response($request, $user);
    }
}
