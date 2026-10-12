<?php

namespace Tests\Feature\Bookings;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The simulated card form, which stands in for the MCB gateway for now.
 */
class PaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kozy.payments.card', 'simulated');
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     */
    private function card(Booking $booking, array $overrides = []): array
    {
        return [
            'booking_id' => $booking->id,
            'card_name' => 'Ada Lovelace',
            'card_number' => '4242 4242 4242 4242',
            'card_expiry' => now()->addYear()->format('m/y'),
            'card_cvc' => '123',
            ...$overrides,
        ];
    }

    public function test_an_approved_test_card_confirms_the_booking(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->withPayment()->create(['client_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->postJson('/api/payments/card', $this->card($booking))
            ->assertOk()
            ->assertJsonPath('booking.status', Booking::STATUS_CONFIRMED)
            ->assertJsonPath('booking.payment.status', Payment::STATUS_PAID);

        $payment = $booking->fresh()->payment;

        $this->assertSame(Payment::METHOD_CARD, $payment->method);
        $this->assertStringStartsWith('SIM-', $payment->transaction_ref);
        $this->assertNotNull($payment->paid_at);
    }

    public function test_the_card_number_is_never_stored(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->withPayment()->create(['client_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->postJson('/api/payments/card', $this->card($booking, ['card_number' => '5555555555554444']))
            ->assertOk();

        $payload = $booking->fresh()->payment->provider_payload;

        $this->assertSame('Mastercard', $payload['brand']);
        $this->assertSame('4444', $payload['last4']);
        $this->assertTrue($payload['simulated']);
        $this->assertStringNotContainsString('5555555555554444', json_encode($payload));
    }

    public function test_a_payment_record_is_created_when_the_booking_has_none(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->create(['client_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->postJson('/api/payments/card', $this->card($booking))
            ->assertOk();

        $this->assertSame(Payment::STATUS_PAID, $booking->fresh()->payment->status);
    }

    public function test_a_declined_card_leaves_the_booking_unpaid(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->withPayment()->create(['client_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->postJson('/api/payments/card', $this->card($booking, ['card_number' => '4000 0000 0000 0002']))
            ->assertStatus(422)
            ->assertJsonPath('declined', true)
            ->assertJsonPath('message', 'Your card was declined.');

        $booking->refresh();

        $this->assertSame(Payment::STATUS_FAILED, $booking->payment->status);
        $this->assertNotSame(Booking::STATUS_CONFIRMED, $booking->status);
    }

    public function test_a_guest_can_retry_after_a_decline(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->withPayment()->create(['client_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->postJson('/api/payments/card', $this->card($booking, ['card_number' => '4000000000009995']))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Your card has insufficient funds.');

        $this->postJson('/api/payments/card', $this->card($booking))->assertOk();

        $this->assertSame(Payment::STATUS_PAID, $booking->fresh()->payment->status);
    }

    public function test_a_card_that_is_not_a_test_card_is_refused(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->withPayment()->create(['client_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->postJson('/api/payments/card', $this->card($booking, ['card_number' => '4111 1111 1111 1111']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('card_number');

        $this->assertSame(Payment::STATUS_PENDING, $booking->fresh()->payment->status);
    }

    public function test_card_details_are_validated(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->withPayment()->create(['client_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->postJson('/api/payments/card', $this->card($booking, [
                'card_name' => '',
                'card_expiry' => '13/40',
                'card_cvc' => '12',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['card_name', 'card_expiry', 'card_cvc']);

        $this->postJson('/api/payments/card', $this->card($booking, [
            'card_expiry' => now()->subMonth()->format('m/y'),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['card_expiry' => 'expired']);
    }

    public function test_a_paid_booking_cannot_be_paid_again(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->withPayment(Payment::STATUS_PAID)->create(['client_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->postJson('/api/payments/card', $this->card($booking))
            ->assertStatus(422);
    }

    public function test_a_cancelled_booking_cannot_be_paid(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->cancelled()->withPayment()->create(['client_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->postJson('/api/payments/card', $this->card($booking))
            ->assertStatus(422);
    }

    public function test_a_client_cannot_pay_someone_elses_booking(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->withPayment()->create();

        $this->actingAs($user, 'web')
            ->postJson('/api/payments/card', $this->card($booking))
            ->assertNotFound();

        $this->assertSame(Payment::STATUS_PENDING, $booking->fresh()->payment->status);
    }

    public function test_nothing_is_taken_when_card_payments_are_disabled(): void
    {
        config()->set('kozy.payments.card', 'disabled');

        $user = User::factory()->create();
        $booking = Booking::factory()->withPayment()->create(['client_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->postJson('/api/payments/card', $this->card($booking))
            ->assertStatus(422)
            ->assertJsonPath('card_payments', false);

        $this->assertSame(Payment::STATUS_PENDING, $booking->fresh()->payment->status);
    }

    public function test_card_payments_are_off_in_production_unless_switched_on(): void
    {
        // Read the config file afresh under each environment, with the
        // setting itself left unset so only the default applies.
        $default = function (string $env): mixed {
            putenv("APP_ENV={$env}");
            putenv('KOZY_CARD_PAYMENTS');
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $env;
            unset($_ENV['KOZY_CARD_PAYMENTS'], $_SERVER['KOZY_CARD_PAYMENTS']);

            return (require config_path('kozy.php'))['payments']['card'];
        };

        try {
            $this->assertSame('disabled', $default('production'));
            $this->assertSame('simulated', $default('local'));
        } finally {
            putenv('APP_ENV=testing');
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
        }
    }
}
