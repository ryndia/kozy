<?php

namespace App\Support;

/**
 * Money questions the listing and booking screens keep asking.
 *
 * Two rules hold everywhere: a listed price already includes VAT, and a booking
 * is always charged in the property's own currency. Everything here either
 * splits out the tax already inside a price, or converts one for reading only.
 */
final class Pricing
{
    /**
     * Split a VAT-inclusive total into the net amount and the tax inside it.
     *
     * The tax is the remainder after the net, not a separate rounding, so the
     * two always add back to exactly the total the guest was quoted.
     *
     * @return array{total: float, net: float, vat: float, rate: float}
     */
    public static function vatBreakdown(float $total): array
    {
        $rate = self::vatRate();
        $net = $rate > 0 ? round($total / (1 + $rate), 2) : $total;

        return [
            'total' => round($total, 2),
            'net' => $net,
            'vat' => round($total - $net, 2),
            'rate' => $rate,
        ];
    }

    public static function vatRate(): float
    {
        return (float) config('kozy.vat.rate', 0);
    }

    /**
     * The VAT rate as a whole number, for labels like "incl. 15% VAT".
     */
    public static function vatPercent(): float
    {
        return round(self::vatRate() * 100, 2);
    }

    /**
     * Convert for display only. Returns null when either currency has no rate
     * configured, so a caller shows the original price rather than a guess.
     */
    public static function convert(float $amount, string $from, string $to): ?float
    {
        $rates = self::rates();
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return round($amount, 2);
        }

        if (! isset($rates[$from], $rates[$to]) || (float) $rates[$from] <= 0) {
            return null;
        }

        return round($amount * ((float) $rates[$to] / (float) $rates[$from]), 2);
    }

    /**
     * The currencies a guest may read prices in, as code => label.
     *
     * @return array<string, string>
     */
    public static function displayCurrencies(): array
    {
        $labels = (array) config('kozy.currency.labels', []);

        $currencies = [];

        foreach (array_keys(self::rates()) as $code) {
            $currencies[$code] = (string) ($labels[$code] ?? $code);
        }

        return $currencies;
    }

    public static function supports(string $currency): bool
    {
        return isset(self::rates()[strtoupper($currency)]);
    }

    /**
     * @return array<string, float>
     */
    private static function rates(): array
    {
        return array_map('floatval', (array) config('kozy.currency.rates', []));
    }
}
