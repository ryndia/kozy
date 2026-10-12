<?php

namespace Tests\Unit;

use App\Support\Pricing;
use Tests\TestCase;

class PricingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kozy.vat.rate', 0.15);
        config()->set('kozy.currency.rates', [
            'MUR' => 1.0,
            'USD' => 0.022,
            'EUR' => 0.020,
        ]);
    }

    public function test_it_splits_the_vat_already_inside_a_listed_price(): void
    {
        $breakdown = Pricing::vatBreakdown(13500.00);

        $this->assertSame(13500.00, $breakdown['total']);
        $this->assertSame(11739.13, $breakdown['net']);
        $this->assertSame(1760.87, $breakdown['vat']);
    }

    public function test_the_split_always_adds_back_to_the_quoted_total(): void
    {
        // Rounding must never leave a price that does not reconcile, whatever
        // the amount - the guest is charged the total, not the parts.
        foreach ([0.01, 7.77, 99.99, 1234.56, 13500, 250000.05] as $total) {
            $breakdown = Pricing::vatBreakdown((float) $total);

            $this->assertSame(
                round((float) $total, 2),
                round($breakdown['net'] + $breakdown['vat'], 2),
                "The split of {$total} must reconcile.",
            );
        }
    }

    public function test_a_zero_vat_rate_leaves_the_price_untouched(): void
    {
        config()->set('kozy.vat.rate', 0);

        $breakdown = Pricing::vatBreakdown(100.0);

        $this->assertSame(100.0, $breakdown['net']);
        $this->assertSame(0.0, $breakdown['vat']);
    }

    public function test_it_converts_between_configured_currencies(): void
    {
        $this->assertSame(90.0, Pricing::convert(4500, 'MUR', 'EUR'));
        $this->assertSame(4545.45, Pricing::convert(100, 'USD', 'MUR'));
        $this->assertSame(4500.0, Pricing::convert(4500, 'MUR', 'MUR'));
    }

    public function test_conversion_is_case_insensitive(): void
    {
        $this->assertSame(90.0, Pricing::convert(4500, 'mur', 'eur'));
    }

    public function test_an_unconfigured_currency_converts_to_null_rather_than_a_guess(): void
    {
        $this->assertNull(Pricing::convert(100, 'MUR', 'JPY'));
        $this->assertNull(Pricing::convert(100, 'JPY', 'MUR'));
    }

    public function test_a_round_trip_returns_the_original_amount(): void
    {
        $euros = Pricing::convert(5000, 'MUR', 'EUR');

        $this->assertNotNull($euros);
        $this->assertEqualsWithDelta(5000, Pricing::convert($euros, 'EUR', 'MUR'), 1.0);
    }

    public function test_display_currencies_are_labelled(): void
    {
        config()->set('kozy.currency.labels', ['MUR' => 'Mauritian rupee']);

        $currencies = Pricing::displayCurrencies();

        $this->assertSame('Mauritian rupee', $currencies['MUR']);
        // A rate with no label still offers its code rather than disappearing.
        $this->assertSame('USD', $currencies['USD']);
    }

    public function test_support_is_reported_for_configured_currencies_only(): void
    {
        $this->assertTrue(Pricing::supports('MUR'));
        $this->assertTrue(Pricing::supports('usd'));
        $this->assertFalse(Pricing::supports('JPY'));
    }
}
