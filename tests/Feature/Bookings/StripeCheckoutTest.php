<?php

namespace Tests\Feature\Bookings;

use App\Actions\Payments\SettleBookingPayment;
use App\Http\Controllers\Api\PaymentController;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Stripe\Checkout\Session;
use Stripe\Exception\InvalidRequestException;
use Tests\TestCase;

/**
 * Stripe Checkout's embedded form: opening a session, and the webhook that
 * confirms the booking once it is paid.
 */
class StripeCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_checkout_test';

    /** @var array<string, mixed>|null */
    public static ?array $sentParams = null;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        self::$sentParams = null;

        config()->set('services.stripe.secret', 'sk_test_123');
        config()->set('services.stripe.key', 'pk_test_123');
        config()->set('services.stripe.webhook_secret', self::WEBHOOK_SECRET);
    }

    /**
     * Stand in for Stripe: record what would have been sent, answer with a
     * session, or fail the way Stripe does.
     */
    private function fakeStripe(?\Throwable $throws = null): void
    {
        $this->app->bind(PaymentController::class, fn ($app) => new class($app->make(SettleBookingPayment::class), $throws) extends PaymentController
        {
            public function __construct(SettleBookingPayment $settle, private readonly ?\Throwable $throws)
            {
                parent::__construct($settle);
            }

            protected function startCheckoutSession(array $params): Session
            {
                StripeCheckoutTest::$sentParams = $params;

                if ($this->throws) {
                    throw $this->throws;
                }

                return Session::constructFrom([
                    'id' => 'cs_test_fake',
                    'object' => 'checkout.session',
                    'client_secret' => 'cs_test_fake_secret_abc',
                ]);
            }
        });
    }

    private function bookingFor(User $guest, float $total = 13500): Booking
    {
        $property = Property::factory()->create(['currency' => 'MUR', 'title' => 'Sea-view villa']);
        $checkIn = today()->addDays(20);

        return Booking::factory()->withPayment()->create([
            'client_id' => $guest->id,
            'property_id' => $property->id,
            'total_price' => $total,
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->copy()->addDays(3)->toDateString(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function webhook(array $payload): TestResponse
    {
        $body = json_encode($payload);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", self::WEBHOOK_SECRET);

        return $this->call('POST', '/api/payments/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $body);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sessionEvent(Booking $booking, array $overrides = [], string $type = 'checkout.session.completed'): array
    {
        return [
            'id' => 'evt_test_cs',
            'object' => 'event',
            'type' => $type,
            'data' => [
                'object' => array_merge([
                    'id' => 'cs_test_fake',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                    'status' => 'complete',
                    'amount_total' => 1350000,
                    'currency' => 'mur',
                    'payment_intent' => 'pi_test_from_checkout',
                    'client_reference_id' => (string) $booking->id,
                    'metadata' => ['booking_id' => (string) $booking->id],
                ], $overrides),
            ],
        ];
    }

    public function test_it_opens_a_session_with_the_checkout_studio_configuration(): void
    {
        $this->fakeStripe();

        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);

        $this->actingAs($guest, 'web')
            ->postJson('/api/payments/checkout-session', ['booking_id' => $booking->id])
            ->assertOk()
            ->assertJsonPath('client_secret', 'cs_test_fake_secret_abc')
            ->assertJsonPath('publishable_key', 'pk_test_123')
            ->assertJsonPath('currency', 'MUR');

        $params = self::$sentParams;

        // Checkout Studio values, exactly as configured.
        $this->assertSame('form', $params['ui_mode']);
        $this->assertSame('auto', $params['billing_address_collection']);
        $this->assertSame(['enabled' => false], $params['phone_number_collection']);
        $this->assertSame(['enabled' => false], $params['automatic_tax']);
        $this->assertSame('auto', $params['submit_type']);
        $this->assertSame(['individual' => ['enabled' => true]], $params['name_collection']);
        $this->assertSame(['payment_method_save' => 'enabled'], $params['saved_payment_method_options']);
        $this->assertSame('custom_embedded_web_0001', $params['integration_identifier']);
        $this->assertArrayNotHasKey('shipping_address_collection', $params);

        // Saving a payment method needs a customer on the session.
        $this->assertSame('always', $params['customer_creation']);

        // A stay is a one-off payment, so no subscription-only parameter.
        $this->assertSame('payment', $params['mode']);
        $this->assertArrayNotHasKey('payment_method_collection', $params);
    }

    public function test_the_line_item_is_the_bookings_own_total(): void
    {
        $this->fakeStripe();

        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);

        $this->actingAs($guest, 'web')
            ->postJson('/api/payments/checkout-session', ['booking_id' => $booking->id])
            ->assertOk();

        $item = self::$sentParams['line_items'][0];

        $this->assertSame(1, $item['quantity']);
        $this->assertSame('mur', $item['price_data']['currency']);
        // 13,500 rupees in cents.
        $this->assertSame(1350000, $item['price_data']['unit_amount']);
        $this->assertSame('Sea-view villa · 3 nights', $item['price_data']['product_data']['name']);

        $this->assertSame((string) $booking->id, self::$sentParams['client_reference_id']);
        $this->assertSame((string) $booking->id, self::$sentParams['metadata']['booking_id']);
    }

    public function test_the_session_is_remembered_against_the_payment(): void
    {
        $this->fakeStripe();

        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);

        $this->actingAs($guest, 'web')
            ->postJson('/api/payments/checkout-session', ['booking_id' => $booking->id])
            ->assertOk();

        $payment = $booking->fresh()->payment;

        $this->assertSame('cs_test_fake', $payment->transaction_ref);
        $this->assertSame(Payment::METHOD_STRIPE, $payment->method);
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
    }

    public function test_it_reports_when_stripe_is_not_configured(): void
    {
        config()->set('services.stripe.secret', null);

        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);

        $this->actingAs($guest, 'web')
            ->postJson('/api/payments/checkout-session', ['booking_id' => $booking->id])
            ->assertStatus(422)
            ->assertJsonPath('stripe_configured', false);
    }

    public function test_a_stripe_error_is_reported_rather_than_hidden(): void
    {
        $this->fakeStripe(new InvalidRequestException('Invalid currency: mur'));

        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);

        $this->actingAs($guest, 'web')
            ->postJson('/api/payments/checkout-session', ['booking_id' => $booking->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Stripe could not start this payment: Invalid currency: mur');

        $this->assertNull($booking->fresh()->payment->transaction_ref);
    }

    public function test_a_guest_cannot_open_a_session_for_someone_elses_booking(): void
    {
        $this->fakeStripe();

        $booking = $this->bookingFor(User::factory()->create());

        $this->actingAs(User::factory()->create(), 'web')
            ->postJson('/api/payments/checkout-session', ['booking_id' => $booking->id])
            ->assertNotFound();

        $this->assertNull(self::$sentParams);
    }

    public function test_a_paid_booking_cannot_be_paid_again(): void
    {
        $this->fakeStripe();

        $guest = User::factory()->create();
        $booking = Booking::factory()->withPayment(Payment::STATUS_PAID)->create(['client_id' => $guest->id]);

        $this->actingAs($guest, 'web')
            ->postJson('/api/payments/checkout-session', ['booking_id' => $booking->id])
            ->assertStatus(422);

        $this->assertNull(self::$sentParams);
    }

    public function test_a_completed_session_confirms_the_booking(): void
    {
        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);
        $booking->payment->forceFill(['transaction_ref' => 'cs_test_fake'])->save();

        $this->webhook($this->sessionEvent($booking))
            ->assertOk()
            ->assertJsonPath('message', 'Payment recorded.');

        $booking->refresh();

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->status);
        $this->assertSame(Payment::STATUS_PAID, $booking->payment->status);
        // Refunds are issued against the PaymentIntent, so that replaces the
        // session id once the money is in.
        $this->assertSame('pi_test_from_checkout', $booking->payment->transaction_ref);
        $this->assertSame('complete', $booking->payment->provider_payload['status']);
    }

    public function test_a_session_superseded_by_a_newer_one_still_settles_through_its_metadata(): void
    {
        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);
        // The guest reopened the dialog, so a newer session is on record.
        $booking->payment->forceFill(['transaction_ref' => 'cs_test_newer'])->save();

        $this->webhook($this->sessionEvent($booking, ['id' => 'cs_test_older']))->assertOk();

        $this->assertSame(Payment::STATUS_PAID, $booking->fresh()->payment->status);
    }

    public function test_an_unpaid_session_does_not_confirm_anything(): void
    {
        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);
        $booking->payment->forceFill(['transaction_ref' => 'cs_test_fake'])->save();

        $this->webhook($this->sessionEvent($booking, ['payment_status' => 'unpaid']))
            ->assertOk()
            ->assertJsonPath('message', 'Awaiting payment.');

        $this->assertSame(Payment::STATUS_PENDING, $booking->fresh()->payment->status);
        $this->assertSame(Booking::STATUS_PENDING, $booking->fresh()->status);
    }

    public function test_a_session_with_the_wrong_total_is_refused(): void
    {
        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);
        $booking->payment->forceFill(['transaction_ref' => 'cs_test_fake'])->save();

        $this->webhook($this->sessionEvent($booking, ['amount_total' => 100]))
            ->assertStatus(422);

        $this->assertSame(Payment::STATUS_PENDING, $booking->fresh()->payment->status);
    }

    public function test_replays_and_the_follow_up_payment_intent_event_settle_only_once(): void
    {
        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);
        $booking->payment->forceFill(['transaction_ref' => 'cs_test_fake'])->save();

        $this->webhook($this->sessionEvent($booking))->assertOk();
        $paidAt = $booking->fresh()->payment->paid_at;

        $this->travel(2)->minutes();

        // Stripe also sends payment_intent.succeeded for the same payment.
        $this->webhook([
            'id' => 'evt_test_pi',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_test_from_checkout',
                'object' => 'payment_intent',
                'status' => 'succeeded',
                'amount' => 1350000,
                'amount_received' => 1350000,
                'currency' => 'mur',
                'metadata' => [],
            ]],
        ])->assertOk();

        $this->webhook($this->sessionEvent($booking))->assertOk();

        $payment = $booking->fresh()->payment;

        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertTrue($paidAt->equalTo($payment->paid_at), 'The first settlement must stand.');
    }

    public function test_an_unknown_session_is_acknowledged_not_retried(): void
    {
        $this->webhook([
            'id' => 'evt_test_unknown',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_nobody',
                'object' => 'checkout.session',
                'payment_status' => 'paid',
                'amount_total' => 100,
                'metadata' => [],
            ]],
        ])->assertOk()->assertJsonPath('message', 'Unknown checkout session.');
    }

    public function test_other_checkout_session_events_are_ignored(): void
    {
        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);
        $booking->payment->forceFill(['transaction_ref' => 'cs_test_fake'])->save();

        $this->webhook($this->sessionEvent($booking, ['status' => 'expired'], 'checkout.session.expired'))
            ->assertOk()
            ->assertJsonPath('message', 'Ignored.');

        $this->assertSame(Payment::STATUS_PENDING, $booking->fresh()->payment->status);
    }
}
