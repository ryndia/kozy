<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\BookingResource\Pages\CreateBooking;
use App\Filament\Resources\BookingResource\Pages\ListBookings;
use App\Filament\Resources\ClientResource\Pages\ListClients;
use App\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Filament\Resources\ReviewResource\Pages\ListReviews;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Review;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'otp_verified_at' => now(),
        ]);

        $this->actingAs($this->admin);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function panelPages(): array
    {
        return [
            'dashboard' => ['/admin'],
            'properties' => ['/admin/properties'],
            'property create' => ['/admin/properties/create'],
            'reservations' => ['/admin/bookings'],
            'reservation create' => ['/admin/bookings/create'],
            'payments' => ['/admin/payments'],
            'payment create' => ['/admin/payments/create'],
            'clients' => ['/admin/clients'],
            'client create' => ['/admin/clients/create'],
            'reviews' => ['/admin/reviews'],
            'review create' => ['/admin/reviews/create'],
            'room photos' => ['/admin/property-images'],
        ];
    }

    #[DataProvider('panelPages')]
    public function test_panel_pages_render(string $url): void
    {
        $this->get($url)->assertOk();
    }

    public function test_every_edit_page_renders(): void
    {
        $property = Property::factory()->create();
        $client = User::factory()->create(['role' => 'user']);
        $booking = Booking::factory()->for($property)->create(['client_id' => $client->id]);
        $payment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => 120,
            'status' => Payment::STATUS_PENDING,
        ]);
        $review = Review::create([
            'property_id' => $property->id,
            'client_id' => $client->id,
            'booking_id' => $booking->id,
            'rating' => 4,
            'comment' => 'Great stay.',
            'status' => 'pending',
        ]);
        $image = $property->images()->create([
            'image_path' => 'properties/example.jpg',
            'room_type' => 'bedroom',
            'room_name' => 'Master bedroom',
            'order' => 0,
            'is_cover' => true,
        ]);

        $this->get("/admin/properties/{$property->id}/edit")->assertOk();
        $this->get("/admin/bookings/{$booking->id}/edit")->assertOk();
        $this->get("/admin/payments/{$payment->id}/edit")->assertOk();
        $this->get("/admin/clients/{$client->id}/edit")->assertOk();
        $this->get("/admin/reviews/{$review->id}/edit")->assertOk();
        $this->get("/admin/property-images/{$image->id}/edit")->assertOk();
    }

    public function test_the_reservation_table_shows_names_rather_than_ids(): void
    {
        $property = Property::factory()->create(['title' => 'Sea View Villa']);
        $client = User::factory()->create(['name' => 'Alice Martin', 'role' => 'user']);
        $booking = Booking::factory()->for($property)->create(['client_id' => $client->id]);

        Livewire::test(ListBookings::class)
            ->assertCanSeeTableRecords([$booking])
            ->assertSee('Sea View Villa')
            ->assertSee('Alice Martin');
    }

    public function test_the_payment_table_names_the_reservation_it_belongs_to(): void
    {
        $property = Property::factory()->create(['title' => 'Mountain Lodge']);
        $client = User::factory()->create(['name' => 'Bruno Lee', 'role' => 'user']);
        $booking = Booking::factory()->for($property)->create(['client_id' => $client->id]);
        $payment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => 450,
            'method' => 'stripe',
            'status' => Payment::STATUS_PENDING,
        ]);

        Livewire::test(ListPayments::class)
            ->assertCanSeeTableRecords([$payment])
            ->assertSee('Mountain Lodge')
            ->assertSee('Bruno Lee')
            ->assertSee($payment->reference);
    }

    public function test_the_review_table_shows_the_property_and_guest(): void
    {
        $property = Property::factory()->create(['title' => 'Lagoon Bungalow']);
        $client = User::factory()->create(['name' => 'Carla Diaz', 'role' => 'user']);
        $review = Review::create([
            'property_id' => $property->id,
            'client_id' => $client->id,
            'rating' => 5,
            'comment' => 'Wonderful stay.',
            'status' => 'pending',
        ]);

        Livewire::test(ListReviews::class)
            ->assertCanSeeTableRecords([$review])
            ->assertSee('Lagoon Bungalow')
            ->assertSee('Carla Diaz');
    }

    public function test_the_client_table_lists_clients_with_their_reservation_count(): void
    {
        $client = User::factory()->create(['name' => 'Dana Fisher', 'role' => 'user']);
        Booking::factory()->count(2)->create(['client_id' => $client->id]);

        Livewire::test(ListClients::class)
            ->assertCanSeeTableRecords([$client])
            ->assertSee('Dana Fisher');
    }

    /**
     * The booking form had no property or guest field, so a reservation could
     * never be created from the panel.
     */
    public function test_a_reservation_can_be_created_from_the_panel(): void
    {
        $property = Property::factory()->create(['price_per_night' => 100]);
        $client = User::factory()->create(['role' => 'user']);

        Livewire::test(CreateBooking::class)
            ->fillForm([
                'property_id' => $property->id,
                'client_id' => $client->id,
                'check_in' => today()->addDays(5)->toDateString(),
                'check_out' => today()->addDays(8)->toDateString(),
                'guests' => 2,
                'status' => Booking::STATUS_CONFIRMED,
                'total_price' => 300,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('bookings', [
            'property_id' => $property->id,
            'client_id' => $client->id,
            'guests' => 2,
            'status' => Booking::STATUS_CONFIRMED,
        ]);
    }

    public function test_a_payment_records_the_reservation_by_name(): void
    {
        $property = Property::factory()->create(['title' => 'Palm Cottage']);
        $client = User::factory()->create(['name' => 'Eve Stone', 'role' => 'user']);
        $booking = Booking::factory()->for($property)->create(['client_id' => $client->id]);

        $this->assertStringContainsString('Palm Cottage', $booking->label);
        $this->assertStringContainsString('Eve Stone', $booking->label);
        $this->assertSame('BK-'.str_pad((string) $booking->id, 5, '0', STR_PAD_LEFT), $booking->reference);
    }

    public function test_reservation_tabs_split_the_list_by_stage(): void
    {
        $upcoming = Booking::factory()->create();
        $cancelled = Booking::factory()->cancelled()->create();

        Livewire::test(ListBookings::class)
            ->set('activeTab', 'cancelled')
            ->assertCanSeeTableRecords([$cancelled])
            ->assertCanNotSeeTableRecords([$upcoming]);
    }

    public function test_a_pending_payment_can_be_marked_as_paid(): void
    {
        $booking = Booking::factory()->create();
        $payment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => 200,
            'status' => Payment::STATUS_PENDING,
        ]);

        Livewire::test(ListPayments::class)
            ->callAction(TestAction::make('markAsPaid')->table($payment));

        $this->assertSame(Payment::STATUS_PAID, $payment->refresh()->status);
        $this->assertNotNull($payment->paid_at);
    }

    public function test_a_client_cannot_reach_the_panel(): void
    {
        $client = User::factory()->create(['role' => 'user', 'otp_verified_at' => now()]);

        $this->actingAs($client)->get('/admin')->assertForbidden();
    }
}
