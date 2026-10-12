<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\ReservationCalendarWidget;
use App\Models\Booking;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReservationCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create([
            'role' => 'admin',
            'otp_verified_at' => now(),
        ]));
    }

    public function test_an_admin_can_open_the_reservation_calendar(): void
    {
        $this->get('/admin/reservation-calendar')
            ->assertOk()
            ->assertSee('Reservation calendar');
    }

    public function test_the_calendar_widget_renders(): void
    {
        Booking::factory()->create();

        Livewire::test(ReservationCalendarWidget::class)
            ->assertOk();
    }

    public function test_a_stay_becomes_a_calendar_event_spanning_its_nights(): void
    {
        $property = Property::factory()->create(['title' => 'Sea-view villa']);
        $guest = User::factory()->create(['name' => 'Amara Devi']);

        $booking = Booking::factory()->create([
            'property_id' => $property->id,
            'client_id' => $guest->id,
            'check_in' => '2026-09-10',
            'check_out' => '2026-09-14',
            'status' => Booking::STATUS_CONFIRMED,
        ]);

        $event = $booking->load(['property', 'client'])->toCalendarEvent();

        $this->assertSame('Sea-view villa · Amara Devi', $event->getTitle());
        $this->assertSame('2026-09-10', $event->getStart()->toDateString());
        // Exclusive end: the 14th is free for the next guest.
        $this->assertSame('2026-09-14', $event->getEnd()->toDateString());
        $this->assertTrue($event->getAllDay());
        $this->assertSame(
            Booking::CALENDAR_COLOURS[Booking::STATUS_CONFIRMED],
            $event->getBackgroundColor(),
        );
    }

    public function test_each_status_gets_its_own_colour(): void
    {
        $colours = Booking::CALENDAR_COLOURS;

        $this->assertCount(4, $colours);
        $this->assertSame($colours, array_unique($colours), 'Statuses must be distinguishable at a glance.');
    }
}
