<?php

namespace Tests\Feature\Bookings;

use App\Actions\Bookings\CancelBookingAction;
use App\Actions\Payments\RefundBookingPayment;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Refund;
use App\Models\User;
use App\Notifications\BookingCancelledNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config()->set('kozy.payments.card', 'simulated');
    }

    private function paidBooking(
        User $guest,
        int $daysUntilCheckIn = 30,
        string $method = Payment::METHOD_MANUAL,
        float $paid = 12000,
        string $reference = 'SIM-TEST12345678',
    ): Booking {
        $property = Property::factory()->create([
            'currency' => 'MUR',
            'cancellation_fee_percent' => 25,
        ]);

        $checkIn = today()->addDays($daysUntilCheckIn);

        $booking = Booking::factory()->create([
            'client_id' => $guest->id,
            'property_id' => $property->id,
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->copy()->addDays(4)->toDateString(),
            'total_price' => $paid,
            'status' => Booking::STATUS_CONFIRMED,
        ]);

        $booking->payment()->create([
            'amount' => $paid,
            'status' => Payment::STATUS_PAID,
            'method' => $method,
            'transaction_ref' => $reference,
            'paid_at' => now(),
        ]);

        return $booking->load(['property', 'payment']);
    }

    public function test_a_guest_is_quoted_the_refund_before_cancelling(): void
    {
        $guest = User::factory()->create();
        $booking = $this->paidBooking($guest, daysUntilCheckIn: 10);

        $this->actingAs($guest, 'web')
            ->getJson("/api/client/bookings/{$booking->id}/cancellation-quote")
            ->assertOk()
            ->assertJsonPath('can_cancel', true)
            ->assertJsonPath('quote.rule', 'fee')
            ->assertJsonPath('quote.fee', 3000)
            ->assertJsonPath('quote.refund', 9000);

        // Quoting changes nothing.
        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->fresh()->status);
        $this->assertSame(0, Refund::query()->count());
    }

    public function test_cancelling_records_the_refund_owed_and_the_fee_kept(): void
    {
        $guest = User::factory()->create();
        $booking = $this->paidBooking($guest, daysUntilCheckIn: 10);

        $this->actingAs($guest, 'web')
            ->postJson("/api/client/bookings/{$booking->id}/cancel", ['reason' => 'Flights cancelled'])
            ->assertOk()
            ->assertJsonPath('quote.refund', 9000)
            ->assertJsonPath('booking.status', Booking::STATUS_CANCELLED);

        $booking->refresh();
        $refund = $booking->refunds()->sole();

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->status);
        $this->assertSame('Flights cancelled', $booking->cancellation_reason);
        $this->assertNotNull($booking->cancelled_at);
        $this->assertSame($guest->id, $booking->cancelled_by_id);

        $this->assertSame(9000.0, (float) $refund->amount);
        $this->assertSame(3000.0, (float) $refund->fee_amount);
        $this->assertSame(Refund::STATUS_PENDING, $refund->status);
        $this->assertSame(Payment::METHOD_MANUAL, $refund->method);
    }

    public function test_the_guest_is_emailed_what_they_get_back(): void
    {
        $guest = User::factory()->create();
        $booking = $this->paidBooking($guest, daysUntilCheckIn: 10);

        $this->actingAs($guest, 'web')
            ->postJson("/api/client/bookings/{$booking->id}/cancel")
            ->assertOk();

        Notification::assertSentTo($guest, BookingCancelledNotification::class, function ($notification) use ($guest) {
            $mail = $notification->toMail($guest);
            $body = implode("\n", $mail->introLines).implode("\n", $mail->outroLines);

            return str_contains($body, '9,000.00') && str_contains($body, '3,000.00');
        });
    }

    public function test_a_payment_taken_by_hand_waits_in_the_payout_queue(): void
    {
        $guest = User::factory()->create();
        $booking = $this->paidBooking($guest);

        app(CancelBookingAction::class)->execute($booking, $guest);

        $refund = $booking->refunds()->sole();
        $payment = $booking->fresh()->payment;

        // Cash, a bank transfer, a manual entry: there is no API to call, so
        // nothing has moved and the payment is still a paid payment.
        $this->assertSame(Refund::STATUS_PENDING, $refund->status);
        $this->assertNull($refund->processed_at);
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame(0.0, (float) $payment->refunded_amount);
        $this->assertSame(1, Refund::query()->outstanding()->count());
    }

    public function test_a_card_refund_is_issued_immediately(): void
    {
        $guest = User::factory()->create();
        $booking = $this->paidBooking($guest, method: Payment::METHOD_CARD);

        app(CancelBookingAction::class)->execute($booking, $guest);

        $refund = $booking->refunds()->sole();
        $payment = $booking->fresh()->payment;

        $this->assertSame(Refund::STATUS_COMPLETED, $refund->status);
        $this->assertStringStartsWith('SIM-RF-', $refund->provider_reference);
        $this->assertNotNull($refund->processed_at);

        // Fully returned, so the payment is now a refunded payment.
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->status);
        $this->assertSame(12000.0, (float) $payment->refunded_amount);
    }

    public function test_a_partial_card_refund_leaves_the_payment_paid(): void
    {
        $guest = User::factory()->create();
        // Ten days' notice keeps 25%, so only 9,000 of 12,000 goes back.
        $booking = $this->paidBooking($guest, daysUntilCheckIn: 10, method: Payment::METHOD_CARD);

        app(CancelBookingAction::class)->execute($booking, $guest);

        $payment = $booking->fresh()->payment;

        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame(9000.0, (float) $payment->refunded_amount);
    }

    public function test_a_card_payment_recorded_by_hand_waits_in_the_payout_queue(): void
    {
        $guest = User::factory()->create();
        // Marked as card in the back office, but not taken by the gateway, so
        // there is nothing for the gateway to reverse.
        $booking = $this->paidBooking($guest, method: Payment::METHOD_CARD, reference: 'POS-88123');

        app(CancelBookingAction::class)->execute($booking, $guest);

        $this->assertSame(Refund::STATUS_PENDING, $booking->refunds()->sole()->status);
    }

    public function test_card_refunds_wait_in_the_queue_while_card_payments_are_disabled(): void
    {
        config()->set('kozy.payments.card', 'disabled');

        $guest = User::factory()->create();
        $booking = $this->paidBooking($guest, method: Payment::METHOD_CARD);

        app(CancelBookingAction::class)->execute($booking, $guest);

        $this->assertSame(Refund::STATUS_PENDING, $booking->refunds()->sole()->status);
        $this->assertSame(Payment::STATUS_PAID, $booking->fresh()->payment->status);
    }

    public function test_marking_a_queued_refund_paid_settles_the_payment(): void
    {
        $guest = User::factory()->create();
        $booking = $this->paidBooking($guest);

        app(CancelBookingAction::class)->execute($booking, $guest);
        $refund = $booking->refunds()->sole();

        app(RefundBookingPayment::class)->markCompleted($refund, 'MCB-TRANSFER-9');

        $refund->refresh();
        $payment = $booking->fresh()->payment;

        $this->assertSame(Refund::STATUS_COMPLETED, $refund->status);
        $this->assertSame('MCB-TRANSFER-9', $refund->provider_reference);
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->status);
        $this->assertSame(12000.0, (float) $payment->refunded_amount);
    }

    public function test_marking_a_refund_paid_twice_does_not_double_count(): void
    {
        $guest = User::factory()->create();
        $booking = $this->paidBooking($guest);

        app(CancelBookingAction::class)->execute($booking, $guest);
        $refund = $booking->refunds()->sole();
        $action = app(RefundBookingPayment::class);

        $action->markCompleted($refund, 'REF-1');
        $action->markCompleted($refund->fresh(), 'REF-2');

        $payment = $booking->fresh()->payment;

        $this->assertSame(12000.0, (float) $payment->refunded_amount);
        $this->assertSame('REF-1', $refund->fresh()->provider_reference);
    }

    public function test_an_unpaid_booking_cancels_without_creating_a_refund(): void
    {
        $guest = User::factory()->create();
        $booking = Booking::factory()->withPayment()->create([
            'client_id' => $guest->id,
            'check_in' => today()->addDays(20)->toDateString(),
            'check_out' => today()->addDays(23)->toDateString(),
        ]);

        $this->actingAs($guest, 'web')
            ->postJson("/api/client/bookings/{$booking->id}/cancel")
            ->assertOk()
            ->assertJsonPath('quote.rule', 'unpaid');

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->fresh()->status);
        $this->assertSame(0, Refund::query()->count());
    }

    public function test_a_guest_cannot_cancel_a_stay_that_has_started(): void
    {
        $guest = User::factory()->create();
        $booking = $this->paidBooking($guest, daysUntilCheckIn: -2);

        $this->actingAs($guest, 'web')
            ->postJson("/api/client/bookings/{$booking->id}/cancel")
            ->assertStatus(422);

        $this->assertSame(0, Refund::query()->count());
    }

    public function test_a_booking_cannot_be_cancelled_twice(): void
    {
        $guest = User::factory()->create();
        $booking = $this->paidBooking($guest);

        $this->actingAs($guest, 'web')
            ->postJson("/api/client/bookings/{$booking->id}/cancel")
            ->assertOk();

        $this->actingAs($guest, 'web')
            ->postJson("/api/client/bookings/{$booking->id}/cancel")
            ->assertStatus(422);

        $this->assertSame(1, Refund::query()->count());
    }

    public function test_a_guest_cannot_cancel_someone_elses_booking(): void
    {
        $booking = $this->paidBooking(User::factory()->create());

        $this->actingAs(User::factory()->create(), 'web')
            ->postJson("/api/client/bookings/{$booking->id}/cancel")
            ->assertNotFound();

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->fresh()->status);
    }

    public function test_an_admin_can_cancel_a_stay_that_has_already_started(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $guest = User::factory()->create();
        $booking = $this->paidBooking($guest, daysUntilCheckIn: -3);

        app(CancelBookingAction::class)->forAdmin($booking, $admin, 'Property flooded');

        $booking->refresh();

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->status);
        $this->assertSame($admin->id, $booking->cancelled_by_id);
        $this->assertSame('Property flooded', $booking->cancellation_reason);
        // The policy refunds nothing once the stay has begun.
        $this->assertSame(0, Refund::query()->count());
    }

    public function test_an_admin_can_override_the_refund(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $guest = User::factory()->create();
        $booking = $this->paidBooking($guest, daysUntilCheckIn: -3);

        app(CancelBookingAction::class)->forAdmin($booking, $admin, 'Our fault', 12000.0);

        $refund = $booking->refunds()->sole();

        $this->assertSame(12000.0, (float) $refund->amount);
        $this->assertSame(0.0, (float) $refund->fee_amount);
        $this->assertSame($admin->id, $refund->requested_by_id);
    }

    public function test_an_admin_override_cannot_refund_more_than_was_paid(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $guest = User::factory()->create();
        $booking = $this->paidBooking($guest);

        app(CancelBookingAction::class)->forAdmin($booking, $admin, 'Goodwill', 99999.0);

        $this->assertSame(12000.0, (float) $booking->refunds()->sole()->amount);
    }
}
