<?php

namespace App\Http\Responses;

use App\Enums\UserRole;
use App\Support\AuthRedirect;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

class RegisterResponse implements RegisterResponseContract
{
    public function toResponse($request)
    {
        $user = $request->user();

        if (! $user) {
            return $request->wantsJson()
                ? new JsonResponse('', 201)
                : redirect()->route('register');
        }

        if ($user->role === UserRole::RENTER->value) {
            $code = $user->sendOtpCode();
            $request->session()->put('otp_code', $code);
            $request->session()->put('otp_expires_at', now()->addMinutes(5)->timestamp);

            return $request->wantsJson()
                ? new JsonResponse(['user' => $user], 201)
                : redirect()->route('otp.verify');
        }

        return $request->wantsJson()
            ? new JsonResponse('', 201)
            : AuthRedirect::response($request, $user);
    }
}
