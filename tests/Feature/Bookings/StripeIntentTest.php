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
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Tests\TestCase;

/**
 * The intent lifecycle, with Stripe stood in for.
 */
class StripeIntentTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> */
    public static array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        self::$calls = [];

        config()->set('services.stripe.secret', 'sk_test_123');
        config()->set('services.stripe.key', 'pk_test_123');
    }

    private function intent(string $id, string $status, int $amount = 1350000, string $currency = 'mur'): PaymentIntent
    {
        return PaymentIntent::constructFrom([
            'id' => $id,
            'object' => 'payment_intent',
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
            'client_secret' => $id.'_secret',
            'metadata' => [],
        ]);
    }

    /**
     * Swap the controller for one that records what it would have asked Stripe.
     */
    private function fakeStripe(?PaymentIntent $existing, ?\Throwable $retrieveThrows = null): void
    {
        $test = $this;

        $this->app->bind(PaymentController::class, fn ($app) => new class($app->make(SettleBookingPayment::class), $existing, $retrieveThrows, $test) extends PaymentController
        {
            public function __construct(
                SettleBookingPayment $settle,
                private readonly ?PaymentIntent $existing,
                private readonly ?\Throwable $retrieveThrows,
                private readonly TestCase $test,
            ) {
                parent::__construct($settle);
            }

            protected function retrieveIntent(string $intentId): PaymentIntent
            {
                StripeIntentTest::$calls[] = ['op' => 'retrieve', 'id' => $intentId];

                if ($this->retrieveThrows) {
                    throw $this->retrieveThrows;
                }

                return $this->existing ?? PaymentIntent::constructFrom([
                    'id' => $intentId,
                    'status' => 'requires_payment_method',
                    'amount' => 0,
                    'currency' => 'mur',
                    'client_secret' => 'secret',
                    'metadata' => [],
                ]);
            }

            protected function updateIntent(string $intentId, int $amount, string $currency): PaymentIntent
            {
                StripeIntentTest::$calls[] = ['op' => 'update', 'id' => $intentId, 'amount' => $amount];

                return PaymentIntent::constructFrom([
                    'id' => $intentId,
                    'status' => 'requires_payment_method',
                    'amount' => $amount,
                    'currency' => $currency,
                    'client_secret' => $intentId.'_secret',
                    'metadata' => [],
                ]);
            }

            protected function createStripeIntent(Booking $booking, int $amount, string $currency): PaymentIntent
            {
                StripeIntentTest::$calls[] = ['op' => 'create', 'amount' => $amount, 'currency' => $currency];

                return PaymentIntent::constructFrom([
                    'id' => 'pi_new',
                    'status' => 'requires_payment_method',
                    'amount' => $amount,
                    'currency' => $currency,
                    'client_secret' => 'pi_new_secret',
                    'metadata' => ['booking_id' => (string) $booking->getKey()],
                ]);
            }
        });
    }

    private function bookingFor(User $guest, float $total = 13500, ?string $existingRef = null): Booking
    {
        $property = Property::factory()->create(['currency' => 'MUR']);

        $booking = Booking::factory()->withPayment()->create([
            'client_id' => $guest->id,
            'property_id' => $property->id,
            'total_price' => $total,
        ]);

        if ($existingRef) {
            $booking->payment->forceFill(['transaction_ref' => $existingRef])->save();
        }

        return $booking;
    }

    public function test_a_first_payment_creates_an_intent_in_the_bookings_currency(): void
    {
        $this->fakeStripe(null);

        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);

        $this->actingAs($guest, 'web')
            ->postJson('/api/payments/create-intent', ['booking_id' => $booking->id])
            ->assertOk()
            ->assertJsonPath('client_secret', 'pi_new_secret')
            ->assertJsonPath('publishable_key', 'pk_test_123')
            ->assertJsonPath('currency', 'MUR');

        // MUR is a two-decimal currency, so 13,500 rupees is 1,350,000 cents.
        $this->assertSame(
            [['op' => 'create', 'amount' => 1350000, 'currency' => 'mur']],
            self::$calls,
        );

        $payment = $booking->fresh()->payment;
        $this->assertSame('pi_new', $payment->transaction_ref);
        $this->assertSame(Payment::METHOD_STRIPE, $payment->method);
    }

    public function test_reopening_the_dialog_reuses_the_existing_intent(): void
    {
        $this->fakeStripe($this->intent('pi_existing', 'requires_payment_method'));

        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest, existingRef: 'pi_existing');

        $this->actingAs($guest, 'web')
            ->postJson('/api/payments/create-intent', ['booking_id' => $booking->id])
            ->assertOk()
            ->assertJsonPath('payment_intent_id', 'pi_existing');

        // Retrieved, not recreated: no orphan intents piling up on Stripe.
        $this->assertSame(['retrieve'], array_column(self::$calls, 'op'));
    }

    public function test_an_intent_whose_amount_drifted_is_brought_back_in_line(): void
    {
        $this->fakeStripe($this->intent('pi_stale', 'requires_payment_method', amount: 999));

        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest, existingRef: 'pi_stale');

        $this->actingAs($guest, 'web')
            ->postJson('/api/payments/create-intent', ['booking_id' => $booking->id])
            ->assertOk();

        $this->assertSame(['retrieve', 'update'], array_column(self::$calls, 'op'));
        $this->assertSame(1350000, self::$calls[1]['amount']);
    }

    public function test_a_cancelled_intent_is_replaced_rather_than_reused(): void
    {
        $this->fakeStripe($this->intent('pi_dead', 'canceled'));

        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest, existingRef: 'pi_dead');

        $this->actingAs($guest, 'web')
            ->postJson('/api/payments/create-intent', ['booking_id' => $booking->id])
            ->assertOk()
            ->assertJsonPath('payment_intent_id', 'pi_new');

        $this->assertSame(['retrieve', 'create'], array_column(self::$calls, 'op'));
    }

    public function test_a_reference_stripe_no_longer_knows_falls_back_to_a_new_intent(): void
    {
        $this->fakeStripe(null, new InvalidRequestException('No such payment_intent'));

        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest, existingRef: 'pi_vanished');

        $this->actingAs($guest, 'web')
            ->postJson('/api/payments/create-intent', ['booking_id' => $booking->id])
            ->assertOk()
            ->assertJsonPath('payment_intent_id', 'pi_new');

        $this->assertSame(['retrieve', 'create'], array_column(self::$calls, 'op'));
    }

    public function test_an_intent_that_already_succeeded_settles_instead_of_charging_again(): void
    {
        $this->fakeStripe($this->intent('pi_done', 'succeeded'));

        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest, existingRef: 'pi_done');

        $this->actingAs($guest, 'web')
            ->postJson('/api/payments/create-intent', ['booking_id' => $booking->id])
            ->assertOk()
            ->assertJsonPath('already_paid', true)
            ->assertJsonPath('booking.status', Booking::STATUS_CONFIRMED);

        $booking->refresh();

        $this->assertSame(Payment::STATUS_PAID, $booking->payment->status);
        $this->assertSame(['retrieve'], array_column(self::$calls, 'op'));
    }

    public function test_confirm_refuses_an_intent_belonging_to_another_booking(): void
    {
        $this->fakeStripe(PaymentIntent::constructFrom([
            'id' => 'pi_someone_else',
            'status' => 'succeeded',
            'amount' => 1350000,
            'currency' => 'mur',
            'client_secret' => 'x',
            'metadata' => ['booking_id' => '999999'],
        ]));

        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest, existingRef: 'pi_someone_else');

        $this->actingAs($guest, 'web')
            ->postJson('/api/payments/confirm', [
                'booking_id' => $booking->id,
                'payment_intent_id' => 'pi_someone_else',
            ])
            ->assertStatus(422);

        $this->assertSame(Payment::STATUS_PENDING, $booking->fresh()->payment->status);
    }

    public function test_confirm_refuses_an_intent_that_has_not_succeeded(): void
    {
        $this->fakeStripe($this->intent('pi_pending', 'requires_payment_method'));

        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest, existingRef: 'pi_pending');

        $this->actingAs($guest, 'web')
            ->postJson('/api/payments/confirm', ['booking_id' => $booking->id])
            ->assertStatus(422)
            ->assertJsonPath('payment_status', 'requires_payment_method');

        $this->assertSame(Payment::STATUS_PENDING, $booking->fresh()->payment->status);
    }
}
