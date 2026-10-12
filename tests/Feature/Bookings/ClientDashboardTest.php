<?php

namespace Tests\Feature\Bookings;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Refund;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_client_pages_require_authentication(): void
    {
        $this->get(route('client.bookings'))->assertRedirect(route('login'));
        $this->get(route('client.profile'))->assertRedirect(route('login'));
    }

    public function test_a_client_can_open_the_booking_pages(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('client.bookings'))->assertOk();
        $this->actingAs($user)->get(route('client.profile'))->assertOk();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_bookings_are_listed_with_a_summary(): void
    {
        $user = User::factory()->create();
        $property = Property::factory()->create();

        Booking::factory()->withPayment(Payment::STATUS_PAID)->create([
            'client_id' => $user->id,
            'property_id' => $property->id,
            'total_price' => 300,
        ]);

        Booking::factory()->past()->withPayment()->create([
            'client_id' => $user->id,
            'property_id' => Property::factory()->create()->id,
            'total_price' => 200,
        ]);

        $response = $this->actingAs($user, 'web')->getJson('/api/client/bookings');

        $response->assertOk();
        $response->assertJsonCount(2, 'bookings');
        $response->assertJsonPath('summary.total', 2);
        $response->assertJsonPath('summary.upcoming', 1);
        $response->assertJsonPath('summary.past', 1);
        $response->assertJsonPath('summary.pending_payments', 1);
        $response->assertJsonPath('summary.reviews_due', 1);
        $response->assertJsonPath('summary.amount_paid', 300);
        $response->assertJsonPath('summary.amount_due', 200);
    }

    public function test_a_client_only_sees_their_own_bookings(): void
    {
        $user = User::factory()->create();
        $otherBooking = Booking::factory()->create();

        $this->actingAs($user, 'web')
            ->getJson('/api/client/bookings')
            ->assertOk()
            ->assertJsonCount(0, 'bookings');

        $this->actingAs($user, 'web')
            ->getJson("/api/client/bookings/{$otherBooking->id}")
            ->assertNotFound();
    }

    public function test_a_client_can_cancel_an_upcoming_booking(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->withPayment(Payment::STATUS_PAID)->create([
            'client_id' => $user->id,
        ]);

        $this->actingAs($user, 'web')
            ->postJson("/api/client/bookings/{$booking->id}/cancel")
            ->assertOk()
            ->assertJsonPath('booking.status', Booking::STATUS_CANCELLED);

        $booking->refresh();

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->status);
        $this->assertNotNull($booking->cancelled_at);

        // The refund is owed, not yet sent: no provider can return this
        // payment automatically, so it waits in the payout queue and the
        // payment stays paid until the money actually goes back.
        $refund = $booking->refunds()->sole();

        $this->assertSame(Refund::STATUS_PENDING, $refund->status);
        $this->assertSame(Payment::STATUS_PAID, $booking->payment->status);
        $this->assertSame(0.0, (float) $booking->payment->refunded_amount);
    }

    public function test_a_started_stay_can_no_longer_be_cancelled(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->past()->create(['client_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->postJson("/api/client/bookings/{$booking->id}/cancel")
            ->assertStatus(422);

        $this->assertSame(Booking::STATUS_COMPLETED, $booking->fresh()->status);
    }

    public function test_a_client_cannot_cancel_someone_elses_booking(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->create();

        $this->actingAs($user, 'web')
            ->postJson("/api/client/bookings/{$booking->id}/cancel")
            ->assertNotFound();
    }

    public function test_a_receipt_can_be_downloaded(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->withPayment(Payment::STATUS_PAID)->create([
            'client_id' => $user->id,
        ]);

        $response = $this->actingAs($user, 'web')->get("/api/client/bookings/{$booking->id}/receipt");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_a_completed_stay_can_be_reviewed_once(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->past()->create(['client_id' => $user->id]);

        $this->actingAs($user, 'web')->postJson('/api/reviews', [
            'property_id' => $booking->property_id,
            'booking_id' => $booking->id,
            'rating' => 5,
            'comment' => 'Wonderful stay, spotless place.',
        ])->assertCreated();

        $this->assertDatabaseCount('reviews', 1);
        $this->assertDatabaseHas('reviews', [
            'booking_id' => $booking->id,
            'status' => 'pending',
        ]);

        $this->actingAs($user, 'web')->postJson('/api/reviews', [
            'property_id' => $booking->property_id,
            'booking_id' => $booking->id,
            'rating' => 4,
            'comment' => 'Trying to review twice.',
        ])->assertStatus(422);
    }

    public function test_an_upcoming_stay_cannot_be_reviewed(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->create(['client_id' => $user->id]);

        $this->actingAs($user, 'web')->postJson('/api/reviews', [
            'property_id' => $booking->property_id,
            'booking_id' => $booking->id,
            'rating' => 5,
            'comment' => 'Too early to say anything.',
        ])->assertForbidden();

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_a_client_can_update_their_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->patchJson('/api/me', [
                'name' => 'Ada Lovelace',
                'phone' => '+230 5 123 4567',
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Ada Lovelace')
            ->assertJsonPath('user.phone', '+230 5 123 4567');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Ada Lovelace',
            'phone' => '+230 5 123 4567',
        ]);
    }

    public function test_reviews_by_other_guests_are_not_attributed_to_the_client(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->past()->create(['client_id' => $user->id]);

        Review::query()->create([
            'property_id' => $booking->property_id,
            'client_id' => User::factory()->create()->id,
            'booking_id' => null,
            'rating' => 3,
            'comment' => 'Someone else stayed here.',
            'status' => 'approved',
        ]);

        $this->actingAs($user, 'web')
            ->getJson('/api/client/bookings')
            ->assertOk()
            ->assertJsonPath('bookings.0.review', null)
            ->assertJsonPath('bookings.0.can_review', true);
    }
}
