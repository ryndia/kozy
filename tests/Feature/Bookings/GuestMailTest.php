<?php

namespace Tests\Feature\Bookings;

use App\Actions\Payments\SettleBookingPayment;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\CheckInReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class GuestMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function bookingFor(User $guest, array $attributes = []): Booking
    {
        $property = Property::factory()->create([
            'currency' => 'MUR',
            'title' => 'Sea-view villa',
            'address' => 'Grand Baie',
        ]);

        return Booking::factory()->withPayment()->create(array_merge([
            'client_id' => $guest->id,
            'property_id' => $property->id,
            'total_price' => 13500,
        ], $attributes));
    }

    public function test_settling_a_payment_mails_the_guest_their_stay_details(): void
    {
        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);

        app(SettleBookingPayment::class)->execute($booking->payment, Payment::METHOD_MANUAL, 'REF-1');

        Notification::assertSentTo($guest, BookingConfirmedNotification::class);
        $this->assertNotNull($booking->fresh()->confirmation_sent_at);
    }

    public function test_the_confirmation_itemises_the_vat_already_inside_the_total(): void
    {
        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);

        app(SettleBookingPayment::class)->execute($booking->payment, Payment::METHOD_MANUAL, 'REF-1');

        Notification::assertSentTo($guest, BookingConfirmedNotification::class, function ($notification) use ($guest) {
            $body = implode("\n", $notification->toMail($guest)->introLines)
                .implode("\n", $notification->toMail($guest)->outroLines);

            // 13,500 inclusive of 15% = 11,739.13 net + 1,760.87 tax.
            return str_contains($body, '13,500.00')
                && str_contains($body, '1,760.87')
                && str_contains($body, '11,739.13');
        });
    }

    public function test_a_replayed_settlement_does_not_mail_the_guest_twice(): void
    {
        $guest = User::factory()->create();
        $booking = $this->bookingFor($guest);
        $settle = app(SettleBookingPayment::class);

        $settle->execute($booking->payment, Payment::METHOD_MANUAL, 'REF-1');
        $settle->execute($booking->fresh()->payment, Payment::METHOD_MANUAL, 'REF-1');

        Notification::assertSentToTimes($guest, BookingConfirmedNotification::class, 1);
    }

    public function test_the_reminder_command_mails_guests_arriving_within_the_lead_time(): void
    {
        $soon = User::factory()->create();
        $later = User::factory()->create();

        $imminent = $this->bookingFor($soon, [
            'check_in' => today()->addDays(2)->toDateString(),
            'check_out' => today()->addDays(5)->toDateString(),
        ]);

        $this->bookingFor($later, [
            'check_in' => today()->addDays(20)->toDateString(),
            'check_out' => today()->addDays(23)->toDateString(),
        ]);

        $this->artisan('bookings:send-check-in-reminders')->assertSuccessful();

        Notification::assertSentTo($soon, CheckInReminderNotification::class);
        Notification::assertNotSentTo($later, CheckInReminderNotification::class);
        $this->assertNotNull($imminent->fresh()->check_in_reminder_sent_at);
    }

    public function test_the_reminder_command_never_mails_the_same_stay_twice(): void
    {
        $guest = User::factory()->create();

        $this->bookingFor($guest, [
            'check_in' => today()->addDays(2)->toDateString(),
            'check_out' => today()->addDays(5)->toDateString(),
        ]);

        $this->artisan('bookings:send-check-in-reminders')->assertSuccessful();
        $this->artisan('bookings:send-check-in-reminders')->assertSuccessful();

        Notification::assertSentToTimes($guest, CheckInReminderNotification::class, 1);
    }

    public function test_cancelled_stays_are_not_reminded(): void
    {
        $guest = User::factory()->create();

        $this->bookingFor($guest, [
            'status' => Booking::STATUS_CANCELLED,
            'check_in' => today()->addDays(2)->toDateString(),
            'check_out' => today()->addDays(5)->toDateString(),
        ]);

        $this->artisan('bookings:send-check-in-reminders')->assertSuccessful();

        Notification::assertNotSentTo($guest, CheckInReminderNotification::class);
    }

    public function test_a_dry_run_sends_nothing(): void
    {
        $guest = User::factory()->create();

        $booking = $this->bookingFor($guest, [
            'check_in' => today()->addDays(2)->toDateString(),
            'check_out' => today()->addDays(5)->toDateString(),
        ]);

        $this->artisan('bookings:send-check-in-reminders --dry-run')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($booking->fresh()->check_in_reminder_sent_at);
    }

    public function test_the_reminder_flags_an_outstanding_balance(): void
    {
        $guest = User::factory()->create();

        $booking = $this->bookingFor($guest, [
            'check_in' => today()->addDays(2)->toDateString(),
            'check_out' => today()->addDays(5)->toDateString(),
        ]);

        $notification = new CheckInReminderNotification($booking);
        $body = implode("\n", $notification->toMail($guest)->introLines);

        $this->assertStringContainsString('Outstanding balance', $body);
    }
}
