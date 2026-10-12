<?php

namespace Tests\Feature\Bookings;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StripePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config()->set('services.stripe.secret', null);
        config()->set('services.stripe.key', null);
        config()->set('services.stripe.webhook_secret', self::WEBHOOK_SECRET);
    }

    private function bookingFor(User $guest, float $total = 13500): Booking
    {
        $property = Property::factory()->create(['currency' => 'MUR']);

        return Booking::factory()->withPayment()->create([
            'client_id' => $guest->id,
            'property_id' => $property->id,
            'total_price' => $total,
        ]);
    }

    /**
     * Stripe signs the raw body with a timestamp; anything else is rejected.
     *
     * @param  array<string, mixed>  $payload
     */
    private function webhook(array $payload, ?string $secret = self::WEBHOOK_SECRET): TestResponse
    {
        $body = json_encode($payload);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", (string) $secret);

        return $this->call(
            'POST',
            '/api/payments/stripe/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            $body,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function intentEvent(string $type, string $intentId, int $amountReceived, int $bookingId): array
    {
        return [
            'id' => 'evt_test_1',
            'object' => 'event',
            'type' => $type,
            'data' => [
                'object' => [
                    'id' => $intentId,
                    'object' => 'payment_intent',
                    'amount' => $amountReceived,
                    'amount_received' => $amountReceived,
                    'currency' => 'mur',
                    'status' => $type === 'payment_intent.succeeded' ? 'succeeded' : 'requires_payment_method',
                    'metadata' => ['booking_id' => (string) $bookingId],
                ],
            ],
        ];
    }

    public function test_card_payments_report_when_stripe_is_not_configured(): void
    {
        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);

        $this->actingAs($guest, 'web')
            ->postJson('/api/payments/create-intent', ['booking_id' => $booking->id])
            ->assertStatus(422)
            ->assertJsonPath('stripe_configured', false);
    }

    public function test_a_payment_can_be_settled_manually_outside_production(): void
    {
        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);

        $this->actingAs($guest, 'web')
            ->postJson('/api/payments/confirm', ['booking_id' => $booking->id])
            ->assertOk()
            ->assertJsonPath('booking.status', Booking::STATUS_CONFIRMED)
            ->assertJsonPath('booking.payment.status', Payment::STATUS_PAID);

        $booking->refresh();

        $this->assertSame(Payment::METHOD_MANUAL, $booking->payment->method);
        $this->assertNotNull($booking->payment->paid_at);
    }

    public function test_production_refuses_manual_settlement_without_stripe(): void
    {
        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);

        app()->detectEnvironment(fn () => 'production');

        $this->withoutMiddleware(PreventRequestForgery::class)
            ->actingAs($guest, 'web')
            ->postJson('/api/payments/confirm', ['booking_id' => $booking->id])
            ->assertStatus(422)
            ->assertJsonPath('stripe_configured', false);

        $this->assertSame(Payment::STATUS_PENDING, $booking->fresh()->payment->status);
    }

    public function test_a_paid_booking_cannot_be_paid_again(): void
    {
        $guest = User::factory()->create();
        $booking = Booking::factory()->withPayment(Payment::STATUS_PAID)->create(['client_id' => $guest->id]);

        $this->actingAs($guest, 'web')
            ->postJson('/api/payments/confirm', ['booking_id' => $booking->id])
            ->assertStatus(422);
    }

    public function test_a_cancelled_booking_cannot_be_paid(): void
    {
        $guest = User::factory()->create();
        $booking = Booking::factory()->cancelled()->withPayment()->create(['client_id' => $guest->id]);

        $this->actingAs($guest, 'web')
            ->postJson('/api/payments/confirm', ['booking_id' => $booking->id])
            ->assertStatus(422);
    }

    public function test_a_client_cannot_pay_someone_elses_booking(): void
    {
        $booking = $this->bookingFor(User::factory()->create());

        $this->actingAs(User::factory()->create(), 'web')
            ->postJson('/api/payments/confirm', ['booking_id' => $booking->id])
            ->assertNotFound();

        $this->assertSame(Payment::STATUS_PENDING, $booking->fresh()->payment->status);
    }

    public function test_a_signed_webhook_settles_the_payment_and_confirms_the_stay(): void
    {
        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);
        $booking->payment->forceFill(['transaction_ref' => 'pi_test_success'])->save();

        $this->webhook($this->intentEvent(
            'payment_intent.succeeded',
            'pi_test_success',
            1350000,
            $booking->id,
        ))->assertOk();

        $booking->refresh();

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->status);
        $this->assertSame(Payment::STATUS_PAID, $booking->payment->status);
        $this->assertSame(Payment::METHOD_STRIPE, $booking->payment->method);
        $this->assertNotNull($booking->payment->paid_at);
        $this->assertSame('succeeded', $booking->payment->provider_payload['status']);
    }

    public function test_an_unsigned_webhook_is_rejected(): void
    {
        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);
        $booking->payment->forceFill(['transaction_ref' => 'pi_test_success'])->save();

        $this->webhook(
            $this->intentEvent('payment_intent.succeeded', 'pi_test_success', 1350000, $booking->id),
            'whsec_wrong',
        )->assertStatus(401);

        $this->assertSame(Payment::STATUS_PENDING, $booking->fresh()->payment->status);
        $this->assertSame(Booking::STATUS_PENDING, $booking->fresh()->status);
    }

    public function test_a_replayed_webhook_does_not_settle_twice(): void
    {
        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);
        $booking->payment->forceFill(['transaction_ref' => 'pi_test_replay'])->save();

        $event = $this->intentEvent('payment_intent.succeeded', 'pi_test_replay', 1350000, $booking->id);

        $this->webhook($event)->assertOk();
        $paidAt = $booking->fresh()->payment->paid_at;

        $this->travel(2)->minutes();
        $this->webhook($event)->assertOk();

        $payment = $booking->fresh()->payment;

        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertTrue($paidAt->equalTo($payment->paid_at), 'The first settlement time must stand.');
    }

    public function test_a_webhook_settling_the_wrong_amount_is_refused(): void
    {
        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);
        $booking->payment->forceFill(['transaction_ref' => 'pi_test_short'])->save();

        $this->webhook($this->intentEvent(
            'payment_intent.succeeded',
            'pi_test_short',
            100,
            $booking->id,
        ))->assertStatus(422);

        $this->assertSame(Payment::STATUS_PENDING, $booking->fresh()->payment->status);
        $this->assertSame(Booking::STATUS_PENDING, $booking->fresh()->status);
    }

    public function test_a_failed_payment_webhook_marks_the_payment_failed(): void
    {
        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);
        $booking->payment->forceFill(['transaction_ref' => 'pi_test_failed'])->save();

        $this->webhook($this->intentEvent(
            'payment_intent.payment_failed',
            'pi_test_failed',
            0,
            $booking->id,
        ))->assertOk();

        $booking->refresh();

        $this->assertSame(Payment::STATUS_FAILED, $booking->payment->status);
        // The stay is untouched, so the guest can try another card.
        $this->assertSame(Booking::STATUS_PENDING, $booking->status);
    }

    public function test_a_late_failure_webhook_cannot_unpay_a_settled_booking(): void
    {
        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);
        $booking->payment->forceFill(['transaction_ref' => 'pi_test_race'])->save();

        $this->webhook($this->intentEvent('payment_intent.succeeded', 'pi_test_race', 1350000, $booking->id))->assertOk();
        $this->webhook($this->intentEvent('payment_intent.payment_failed', 'pi_test_race', 0, $booking->id))->assertOk();

        $this->assertSame(Payment::STATUS_PAID, $booking->fresh()->payment->status);
    }

    public function test_a_webhook_for_an_unknown_intent_is_acknowledged_not_retried(): void
    {
        $this->webhook($this->intentEvent('payment_intent.succeeded', 'pi_nobody_knows', 1000, 999))
            ->assertOk()
            ->assertJsonPath('message', 'Unknown payment intent.');
    }

    public function test_an_unrelated_event_type_is_ignored(): void
    {
        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);
        $booking->payment->forceFill(['transaction_ref' => 'pi_test_other'])->save();

        $this->webhook($this->intentEvent('payment_intent.created', 'pi_test_other', 1350000, $booking->id))
            ->assertOk()
            ->assertJsonPath('message', 'Ignored.');

        $this->assertSame(Payment::STATUS_PENDING, $booking->fresh()->payment->status);
    }

    public function test_webhooks_are_refused_when_no_signing_secret_is_configured(): void
    {
        config()->set('services.stripe.webhook_secret', null);

        $this->webhook($this->intentEvent('payment_intent.succeeded', 'pi_x', 100, 1))
            ->assertStatus(400);
    }
}
