<?php

namespace App\Http\Middleware;

use App\Support\Pricing;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Read-only conversion for browsing. Bookings are always priced and
            // charged in the property's own currency - the UI says so wherever
            // a converted figure is shown.
            'pricing' => [
                'vat_rate' => Pricing::vatRate(),
                'vat_percent' => Pricing::vatPercent(),
                'vat_label' => config('kozy.vat.label', 'VAT'),
                'currencies' => Pricing::displayCurrencies(),
                'rates' => config('kozy.currency.rates', []),
            ],
            // "simulated" or "disabled" - decides what the pay dialog offers.
            'payments' => [
                'card' => config('kozy.payments.card'),
            ],
        ];
    }
}
