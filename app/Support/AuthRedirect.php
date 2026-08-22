<?php

namespace App\Support;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides where a user lands once they are authenticated, and how to get them
 * there.
 *
 * The client area is an Inertia app while the back office is a Filament panel
 * rendered as plain Blade/Livewire. An Inertia visit can only follow a redirect
 * that ends on another Inertia response: when it receives regular HTML instead,
 * Inertia drops that HTML into its error dialog, which is why logging in as an
 * admin used to pop the panel open in a floating window. Anything pointing at
 * the panel therefore has to leave Inertia through `Inertia::location()`, which
 * asks the browser for a full page visit.
 */
class AuthRedirect
{
    /**
     * The id of the Filament panel that holds the back office.
     */
    public const PANEL_ID = 'admin';

    /**
     * The post-authentication response for the given user.
     */
    public static function response(Request $request, User $user): Response
    {
        return static::redirect(static::destination($request, $user));
    }

    /**
     * The URL the user should land on, honouring a safe intended URL.
     */
    public static function destination(Request $request, User $user): string
    {
        $intended = $request->hasSession()
            ? $request->session()->pull('url.intended')
            : null;

        return static::sanitiseIntended(is_string($intended) ? $intended : null, $user)
            ?? static::homeUrl($user);
    }

    /**
     * The default landing page for the user's role.
     */
    public static function homeUrl(User $user): string
    {
        return $user->isAdmin()
            ? static::panelUrl()
            : route('dashboard', absolute: false);
    }

    /**
     * Redirect to a URL, leaving Inertia behind when the target is the panel.
     */
    public static function redirect(string $url): Response
    {
        return static::isPanelUrl($url)
            ? Inertia::location($url)
            : Redirect::to($url);
    }

    /**
     * The absolute URL of the Filament admin panel.
     */
    public static function panelUrl(): string
    {
        return url(static::panelPath());
    }

    /**
     * Whether the URL points inside the Filament admin panel.
     */
    public static function isPanelUrl(string $url): bool
    {
        $path = static::toRelativePath($url);

        if ($path === null) {
            return false;
        }

        $panel = static::panelPath();

        return $path === $panel || str_starts_with($path, rtrim($panel, '/').'/');
    }

    /**
     * The panel path, always with a leading slash (e.g. "/admin").
     */
    protected static function panelPath(): string
    {
        return '/'.trim(Filament::getPanel(static::PANEL_ID)->getPath(), '/');
    }

    /**
     * Keep an intended URL only when it is a same-origin page this user may
     * actually open, so login never bounces back to an auth screen or drops a
     * client on a panel they cannot access.
     */
    protected static function sanitiseIntended(?string $intended, User $user): ?string
    {
        if ($intended === null || trim($intended) === '') {
            return null;
        }

        $path = static::toRelativePath($intended);

        if ($path === null) {
            return null;
        }

        $blocked = [
            '/login', '/login/*',
            '/register', '/register/*',
            '/otp/verify', '/otp/verify/*',
            '/client/auth/*',
            '/two-factor-challenge',
            '/forgot-password',
            '/reset-password/*',
            rtrim(static::panelPath(), '/').'/login',
        ];

        if (Str::is($blocked, Str::before($path, '?'))) {
            return null;
        }

        if (static::isPanelUrl($path) && ! static::canAccessPanel($user)) {
            return null;
        }

        return $path;
    }

    protected static function canAccessPanel(User $user): bool
    {
        return $user->canAccessPanel(Filament::getPanel(static::PANEL_ID));
    }

    /**
     * Reduce a URL to a same-origin path with its query string, or null when it
     * points somewhere else entirely.
     */
    protected static function toRelativePath(string $url): ?string
    {
        $url = trim($url);

        // Protocol-relative URLs ("//example.com") leave the application.
        if (str_starts_with($url, '//')) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        if (isset($parts['host']) && ! in_array($parts['host'], static::allowedHosts(), true)) {
            return null;
        }

        $path = $parts['path'] ?? '/';

        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return rtrim($path, '/') === ''
            ? '/'.(isset($parts['query']) ? '?'.$parts['query'] : '')
            : rtrim($path, '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /**
     * @return array<int, string>
     */
    protected static function allowedHosts(): array
    {
        return array_values(array_filter([
            parse_url((string) config('app.url'), PHP_URL_HOST),
            request()->getHost(),
        ]));
    }
}
