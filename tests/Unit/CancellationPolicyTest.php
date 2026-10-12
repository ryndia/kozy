<?php

namespace Tests\Unit;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Support\CancellationPolicy;
use App\Support\CancellationQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancellationPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A paid stay starting the given number of days from today.
     */
    private function booking(int $daysUntilCheckIn, float $paid = 12000, float $feePercent = 25, int $nights = 4): Booking
    {
        $property = Property::factory()->create([
            'currency' => 'MUR',
            'cancellation_fee_percent' => $feePercent,
        ]);

        $checkIn = today()->addDays($daysUntilCheckIn);

        $booking = Booking::factory()->create([
            'property_id' => $property->id,
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->copy()->addDays($nights)->toDateString(),
            'total_price' => $paid,
        ]);

        $booking->payment()->create([
            'amount' => $paid,
            'status' => Payment::STATUS_PAID,
            'method' => Payment::METHOD_MANUAL,
            'paid_at' => now(),
        ]);

        return $booking->load(['property', 'payment']);
    }

    public function test_plenty_of_notice_is_refunded_in_full(): void
    {
        $quote = CancellationPolicy::quote($this->booking(30));

        $this->assertSame(CancellationQuote::RULE_FULL, $quote->rule);
        $this->assertSame(0.0, $quote->fee);
        $this->assertSame(12000.0, $quote->refund);
        $this->assertTrue($quote->isFullRefund());
    }

    public function test_the_boundary_day_still_gets_the_better_tier(): void
    {
        // Exactly 14 days is "more than a fortnight" for our purposes: the tier
        // is inclusive, and a guest on the boundary should not be penalised.
        $quote = CancellationPolicy::quote($this->booking(14));

        $this->assertSame(CancellationQuote::RULE_FULL, $quote->rule);
        $this->assertSame(12000.0, $quote->refund);
    }

    public function test_inside_a_fortnight_keeps_the_properties_own_fee(): void
    {
        $quote = CancellationPolicy::quote($this->booking(10));

        $this->assertSame(CancellationQuote::RULE_FEE, $quote->rule);
        $this->assertSame(3000.0, $quote->fee);
        $this->assertSame(9000.0, $quote->refund);
        $this->assertFalse($quote->isFullRefund());
    }

    public function test_each_property_can_set_its_own_fee(): void
    {
        $quote = CancellationPolicy::quote($this->booking(10, feePercent: 40));

        $this->assertSame(4800.0, $quote->fee);
        $this->assertSame(7200.0, $quote->refund);
    }

    public function test_inside_a_week_keeps_the_first_night(): void
    {
        // 12,000 over 4 nights, so one night is 3,000.
        $quote = CancellationPolicy::quote($this->booking(3));

        $this->assertSame(CancellationQuote::RULE_FIRST_NIGHT, $quote->rule);
        $this->assertSame(3000.0, $quote->fee);
        $this->assertSame(9000.0, $quote->refund);
    }

    public function test_the_first_night_is_a_share_of_what_was_paid_not_the_listed_rate(): void
    {
        // A discounted stay must not forfeit more than a night actually cost.
        $quote = CancellationPolicy::quote($this->booking(2, paid: 4000, nights: 4));

        $this->assertSame(1000.0, $quote->fee);
        $this->assertSame(3000.0, $quote->refund);
    }

    public function test_a_stay_that_has_started_is_not_refunded(): void
    {
        $quote = CancellationPolicy::quote($this->booking(-2));

        $this->assertSame(CancellationQuote::RULE_NONE, $quote->rule);
        $this->assertSame(0.0, $quote->refund);
        $this->assertFalse($quote->hasRefund());
    }

    public function test_cancelling_on_the_day_of_check_in_keeps_the_first_night(): void
    {
        $quote = CancellationPolicy::quote($this->booking(0));

        $this->assertSame(CancellationQuote::RULE_FIRST_NIGHT, $quote->rule);
        $this->assertSame(9000.0, $quote->refund);
    }

    public function test_an_unpaid_booking_has_nothing_to_refund(): void
    {
        $property = Property::factory()->create(['currency' => 'MUR']);
        $booking = Booking::factory()->withPayment()->create([
            'property_id' => $property->id,
            'check_in' => today()->addDays(20)->toDateString(),
            'check_out' => today()->addDays(23)->toDateString(),
        ])->load(['property', 'payment']);

        $quote = CancellationPolicy::quote($booking);

        $this->assertSame(CancellationQuote::RULE_UNPAID, $quote->rule);
        $this->assertSame(0.0, $quote->paid);
        $this->assertSame(0.0, $quote->fee);
        $this->assertSame(0.0, $quote->refund);
    }

    public function test_money_already_refunded_is_not_offered_again(): void
    {
        $booking = $this->booking(30);
        $booking->payment->forceFill(['refunded_amount' => 5000])->save();

        $quote = CancellationPolicy::quote($booking->fresh()->load(['property', 'payment']));

        $this->assertSame(7000.0, $quote->paid);
        $this->assertSame(7000.0, $quote->refund);
    }

    public function test_the_fee_never_exceeds_what_was_paid(): void
    {
        foreach ([1, 3, 7, 10, 14, 30] as $days) {
            $quote = CancellationPolicy::quote($this->booking($days, paid: 100, nights: 1));

            $this->assertLessThanOrEqual($quote->paid, $quote->fee, "Day {$days}");
            $this->assertGreaterThanOrEqual(0, $quote->refund, "Day {$days}");
            $this->assertSame(
                round($quote->paid, 2),
                round($quote->fee + $quote->refund, 2),
                "Day {$days}: the fee and refund must account for everything paid.",
            );
        }
    }
}
