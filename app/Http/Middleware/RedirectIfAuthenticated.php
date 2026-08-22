<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AuthRedirect;
use Closure;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends an already-authenticated visitor away from the guest screens.
 *
 * Laravel's version always redirects to the dashboard. Admins belong in the
 * Filament panel instead, and that is a plain Blade page: reaching it from an
 * Inertia visit needs a full page visit, or Inertia renders the panel inside
 * its error dialog as a floating window.
 */
class RedirectIfAuthenticated extends Middleware
{
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        $guards = $guards === [] ? [null] : $guards;

        foreach ($guards as $guard) {
            if (! Auth::guard($guard)->check()) {
                continue;
            }

            $user = Auth::guard($guard)->user();

            return AuthRedirect::redirect($user instanceof User
                ? AuthRedirect::homeUrl($user)
                : ($this->redirectTo($request) ?? '/'));
        }

        return $next($request);
    }
}
