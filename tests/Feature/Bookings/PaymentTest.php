<?php

namespace Tests\Feature\Bookings;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.stripe.secret', null);
    }

    public function test_card_payments_report_when_stripe_is_not_configured(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->withPayment()->create(['client_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->postJson('/api/payments/create-intent', ['booking_id' => $booking->id])
            ->assertStatus(422)
            ->assertJsonPath('stripe_configured', false);
    }

    public function test_a_payment_can_be_settled_manually_outside_production(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->withPayment()->create(['client_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->postJson('/api/payments/confirm', ['booking_id' => $booking->id])
            ->assertOk()
            ->assertJsonPath('booking.status', Booking::STATUS_CONFIRMED)
            ->assertJsonPath('booking.payment.status', Payment::STATUS_PAID);

        $booking->refresh();

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->status);
        $this->assertSame(Payment::STATUS_PAID, $booking->payment->status);
        $this->assertNotNull($booking->payment->paid_at);
    }

    public function test_a_paid_booking_cannot_be_paid_again(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->withPayment(Payment::STATUS_PAID)->create(['client_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->postJson('/api/payments/confirm', ['booking_id' => $booking->id])
            ->assertStatus(422);
    }

    public function test_a_cancelled_booking_cannot_be_paid(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->cancelled()->withPayment()->create(['client_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->postJson('/api/payments/confirm', ['booking_id' => $booking->id])
            ->assertStatus(422);
    }

    public function test_a_client_cannot_pay_someone_elses_booking(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->withPayment()->create();

        $this->actingAs($user, 'web')
            ->postJson('/api/payments/confirm', ['booking_id' => $booking->id])
            ->assertNotFound();

        $this->assertSame(Payment::STATUS_PENDING, $booking->fresh()->payment->status);
    }

    public function test_production_refuses_manual_settlement_without_stripe(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->withPayment()->create(['client_id' => $user->id]);

        // The CSRF middleware only relaxes itself outside production, so it has
        // to step aside while we pretend this request runs on production.
        app()->detectEnvironment(fn () => 'production');

        $this->withoutMiddleware(PreventRequestForgery::class)
            ->actingAs($user, 'web')
            ->postJson('/api/payments/confirm', ['booking_id' => $booking->id])
            ->assertStatus(422)
            ->assertJsonPath('stripe_configured', false);

        $this->assertSame(Payment::STATUS_PENDING, $booking->fresh()->payment->status);
    }
}
