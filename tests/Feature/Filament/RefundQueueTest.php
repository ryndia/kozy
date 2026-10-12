<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\BookingResource\Pages\ListBookings;
use App\Filament\Resources\RefundResource;
use App\Filament\Resources\RefundResource\Pages\ListRefunds;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Refund;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class RefundQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // Every refund waits in the queue rather than completing on its own.
        config()->set('kozy.payments.card', 'disabled');

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'otp_verified_at' => now(),
        ]);

        $this->actingAs($this->admin);
    }

    private function paidBooking(int $daysUntilCheckIn = 30): Booking
    {
        $property = Property::factory()->create([
            'currency' => 'MUR',
            'cancellation_fee_percent' => 25,
        ]);

        $checkIn = today()->addDays($daysUntilCheckIn);

        $booking = Booking::factory()->create([
            'property_id' => $property->id,
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->copy()->addDays(4)->toDateString(),
            'total_price' => 12000,
            'status' => Booking::STATUS_CONFIRMED,
        ]);

        $booking->payment()->create([
            'amount' => 12000,
            'status' => Payment::STATUS_PAID,
            'method' => Payment::METHOD_MANUAL,
            'paid_at' => now(),
        ]);

        return $booking->load(['property', 'payment']);
    }

    public function test_an_admin_can_open_the_refund_queue(): void
    {
        $this->get(RefundResource::getUrl('index'))->assertOk();
    }

    public function test_the_queue_opens_on_what_is_still_owed(): void
    {
        $outstanding = Refund::factory()->create();
        $done = Refund::factory()->completed()->create();

        // The default tab is the payout queue: work still to do, not history.
        Livewire::test(ListRefunds::class)
            ->assertCanSeeTableRecords([$outstanding])
            ->assertCanNotSeeTableRecords([$done]);
    }

    public function test_completed_refunds_are_still_reachable(): void
    {
        $outstanding = Refund::factory()->create();
        $done = Refund::factory()->completed()->create();

        Livewire::test(ListRefunds::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$outstanding, $done]);
    }

    public function test_the_navigation_badge_counts_only_what_is_owed(): void
    {
        Refund::factory()->count(2)->create();
        Refund::factory()->completed()->create();

        $this->assertSame('2', RefundResource::getNavigationBadge());
    }

    public function test_the_badge_is_hidden_when_nothing_is_owed(): void
    {
        Refund::factory()->completed()->create();

        $this->assertNull(RefundResource::getNavigationBadge());
    }

    public function test_an_admin_cancels_a_booking_and_the_refund_joins_the_queue(): void
    {
        $booking = $this->paidBooking(daysUntilCheckIn: 10);

        Livewire::test(ListBookings::class)
            ->callAction(
                TestAction::make('cancel')->table($booking),
                ['reason' => 'Owner withdrew the property', 'refund_amount' => 9000],
            )
            ->assertHasNoActionErrors();

        $booking->refresh();

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->status);
        $this->assertSame($this->admin->id, $booking->cancelled_by_id);
        $this->assertSame('Owner withdrew the property', $booking->cancellation_reason);
        $this->assertSame(9000.0, (float) $booking->refunds()->sole()->amount);
    }

    public function test_the_admin_cancel_action_requires_a_reason(): void
    {
        $booking = $this->paidBooking();

        Livewire::test(ListBookings::class)
            ->callAction(TestAction::make('cancel')->table($booking), ['reason' => ''])
            ->assertHasActionErrors(['reason']);

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->fresh()->status);
    }

    public function test_an_admin_marks_a_queued_refund_as_paid_out(): void
    {
        $booking = $this->paidBooking();
        $payment = $booking->payment;

        $refund = Refund::factory()->create([
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
            'amount' => 12000,
            'currency' => 'MUR',
        ]);

        Livewire::test(ListRefunds::class)
            ->callAction(
                TestAction::make('markPaid')->table($refund),
                ['provider_reference' => 'MCB-9931'],
            )
            ->assertHasNoActionErrors();

        $refund->refresh();

        $this->assertSame(Refund::STATUS_COMPLETED, $refund->status);
        $this->assertSame('MCB-9931', $refund->provider_reference);
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
    }
}
