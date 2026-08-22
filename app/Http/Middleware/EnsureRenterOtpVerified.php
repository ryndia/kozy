<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Symfony\Component\HttpFoundation\Response;

class EnsureRenterOtpVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && $request->user()->requiresOtpVerification()) {
            if ($request->routeIs('otp.verify') || $request->routeIs('otp.verify.store')) {
                return $next($request);
            }

            return Redirect::route('otp.verify');
        }

        return $next($request);
    }
}
