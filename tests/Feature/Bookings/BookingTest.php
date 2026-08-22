<?php

namespace Tests\Feature\Bookings;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_can_reserve_available_dates(): void
    {
        $user = User::factory()->create();
        $property = Property::factory()->create([
            'price_per_night' => 100,
            'max_guests' => 4,
        ]);

        $response = $this->actingAs($user, 'web')->postJson('/api/bookings', [
            'property_id' => $property->id,
            'check_in' => today()->addDays(3)->toDateString(),
            'check_out' => today()->addDays(6)->toDateString(),
            'guests' => 2,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('booking.nights', 3);
        $response->assertJsonPath('booking.total_price', 300);
        $response->assertJsonPath('booking.status', 'pending');

        $this->assertDatabaseHas('bookings', [
            'property_id' => $property->id,
            'client_id' => $user->id,
            'total_price' => 300,
        ]);

        $this->assertDatabaseHas('payments', [
            'booking_id' => $response->json('booking.id'),
            'status' => Payment::STATUS_PENDING,
        ]);
    }

    public function test_guests_cannot_reserve_without_logging_in(): void
    {
        $property = Property::factory()->create();

        $this->postJson('/api/bookings', [
            'property_id' => $property->id,
            'check_in' => today()->addDay()->toDateString(),
            'check_out' => today()->addDays(3)->toDateString(),
            'guests' => 1,
        ])->assertUnauthorized();
    }

    public function test_overlapping_dates_are_rejected(): void
    {
        $user = User::factory()->create();
        $property = Property::factory()->create();

        Booking::factory()->create([
            'property_id' => $property->id,
            'check_in' => today()->addDays(5)->toDateString(),
            'check_out' => today()->addDays(9)->toDateString(),
        ]);

        $this->actingAs($user, 'web')->postJson('/api/bookings', [
            'property_id' => $property->id,
            'check_in' => today()->addDays(7)->toDateString(),
            'check_out' => today()->addDays(11)->toDateString(),
            'guests' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors('check_in');
    }

    public function test_a_stay_may_start_on_the_day_another_one_ends(): void
    {
        $user = User::factory()->create();
        $property = Property::factory()->create();

        Booking::factory()->create([
            'property_id' => $property->id,
            'check_in' => today()->addDays(5)->toDateString(),
            'check_out' => today()->addDays(9)->toDateString(),
        ]);

        $this->actingAs($user, 'web')->postJson('/api/bookings', [
            'property_id' => $property->id,
            'check_in' => today()->addDays(9)->toDateString(),
            'check_out' => today()->addDays(12)->toDateString(),
            'guests' => 1,
        ])->assertCreated();
    }

    public function test_cancelled_bookings_free_up_their_dates(): void
    {
        $user = User::factory()->create();
        $property = Property::factory()->create();

        Booking::factory()->cancelled()->create([
            'property_id' => $property->id,
            'check_in' => today()->addDays(5)->toDateString(),
            'check_out' => today()->addDays(9)->toDateString(),
        ]);

        $this->actingAs($user, 'web')->postJson('/api/bookings', [
            'property_id' => $property->id,
            'check_in' => today()->addDays(5)->toDateString(),
            'check_out' => today()->addDays(9)->toDateString(),
            'guests' => 1,
        ])->assertCreated();
    }

    public function test_guest_count_cannot_exceed_the_property_capacity(): void
    {
        $user = User::factory()->create();
        $property = Property::factory()->create(['max_guests' => 2]);

        $this->actingAs($user, 'web')->postJson('/api/bookings', [
            'property_id' => $property->id,
            'check_in' => today()->addDays(3)->toDateString(),
            'check_out' => today()->addDays(5)->toDateString(),
            'guests' => 5,
        ])->assertStatus(422)->assertJsonValidationErrors('guests');
    }

    public function test_inactive_properties_cannot_be_reserved(): void
    {
        $user = User::factory()->create();
        $property = Property::factory()->inactive()->create();

        $this->actingAs($user, 'web')->postJson('/api/bookings', [
            'property_id' => $property->id,
            'check_in' => today()->addDays(3)->toDateString(),
            'check_out' => today()->addDays(5)->toDateString(),
            'guests' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors('property_id');
    }

    public function test_past_check_in_dates_are_rejected(): void
    {
        $user = User::factory()->create();
        $property = Property::factory()->create();

        $this->actingAs($user, 'web')->postJson('/api/bookings', [
            'property_id' => $property->id,
            'check_in' => today()->subDay()->toDateString(),
            'check_out' => today()->addDays(2)->toDateString(),
            'guests' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors('check_in');
    }
}
