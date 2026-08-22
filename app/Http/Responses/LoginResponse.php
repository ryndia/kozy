<?php

namespace App\Http\Responses;

use App\Enums\UserRole;
use App\Support\AuthRedirect;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        $user = $request->user();

        if (! $user) {
            return $request->wantsJson()
                ? response()->json(['two_factor' => false])
                : redirect()->route('login');
        }

        if (! UserRole::isValid((string) $user->role)) {
            $user->assignRole(UserRole::USER->value);
        }

        if ($user->requiresOtpVerification()) {
            $code = $user->sendOtpCode();

            $request->session()->put('otp_code', $code);
            $request->session()->put('otp_expires_at', now()->addMinutes(5)->timestamp);
            $request->session()->put('otp_user_id', $user->getKey());

            return redirect()->route('otp.verify');
        }

        if ($request->wantsJson()) {
            return response()->json([
                'two_factor' => false,
                'redirect_url' => AuthRedirect::destination($request, $user),
            ]);
        }

        // Admins land in the Filament panel, everyone else in the client area.
        // AuthRedirect turns the panel into a full page visit so Inertia never
        // renders it inside its error dialog.
        return AuthRedirect::response($request, $user);
    }
}
