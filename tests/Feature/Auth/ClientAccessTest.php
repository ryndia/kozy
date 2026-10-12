<?php

namespace Tests\Feature\Auth;

use App\Models\Booking;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ClientAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_screen_renders_the_password_form(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/login'));
    }

    public function test_an_existing_email_is_asked_for_its_password_instead_of_signing_up(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'guest@example.com']);
        $originalPassword = $user->password;

        $this->postJson(route('client.auth.start'), [
            'name' => 'Impostor',
            'email' => 'guest@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('account_exists', true);

        Notification::assertNothingSent();
        $this->assertSame($originalPassword, $user->fresh()->password);
        $this->assertGuest();
    }

    public function test_the_sign_up_flow_cannot_overwrite_an_existing_password(): void
    {
        $user = User::factory()->create(['email' => 'guest@example.com']);
        $originalPassword = $user->password;

        // Simulate a verified sign-up session pointing at an existing account.
        $this->withSession([
            'client_auth_flow' => [
                'name' => 'Impostor',
                'email' => 'guest@example.com',
                'verified' => true,
                'intended' => '/client/bookings',
                'reservation' => null,
            ],
        ])->postJson(route('client.auth.password.store'), [
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(422);

        $this->assertSame($originalPassword, $user->fresh()->password);
        $this->assertGuest();
    }

    public function test_a_new_guest_without_a_stay_chooses_their_password_straight_away(): void
    {
        Notification::fake();

        $this->postJson(route('client.auth.start'), [
            'name' => 'New Guest',
            'email' => 'new@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('account_exists', false)
            ->assertJsonPath('redirect_url', '/client/auth/password');

        // Nothing is mailed mid-signup - no verification code.
        Notification::assertNothingSent();

        $user = User::query()->where('email', 'new@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->must_set_password);
        $this->assertSame('user', $user->role);

        $this->get(route('client.auth.password'))->assertOk();

        $this->postJson(route('client.auth.password.store'), [
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])
            ->assertOk()
            ->assertJsonPath('redirect_url', '/client/bookings');

        $user->refresh();

        $this->assertFalse($user->must_set_password);
        $this->assertTrue(Hash::check('a-strong-password', $user->password));
    }

    public function test_a_guest_reserving_from_a_property_page_goes_straight_to_payment(): void
    {
        Notification::fake();

        $property = Property::factory()->create(['price_per_night' => 150, 'max_guests' => 4]);

        $response = $this->postJson(route('client.auth.start'), [
            'name' => 'Booking Guest',
            'email' => 'booker@example.com',
            'reservation' => [
                'property_id' => $property->id,
                'check_in' => today()->addDays(2)->toDateString(),
                'check_out' => today()->addDays(4)->toDateString(),
                'guests' => 3,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('account_exists', false);

        $user = User::query()->where('email', 'booker@example.com')->firstOrFail();
        $booking = Booking::query()->where('client_id', $user->id)->sole();

        // The booking exists before any password, and the guest lands on its
        // payment.
        $response->assertJsonPath('booking_id', $booking->id)
            ->assertJsonPath('redirect_url', "/client/bookings?pay={$booking->id}");

        $this->assertSame($property->id, $booking->property_id);
        $this->assertSame(3, $booking->guests);
        $this->assertSame(300.0, (float) $booking->total_price);
        $this->assertSame(Booking::STATUS_PENDING, $booking->status);

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->must_set_password);

        // Paying is open to them right away.
        $this->getJson("/api/client/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('booking.can_pay', true);

        // And the password comes afterwards.
        $this->postJson(route('client.auth.password.store'), [
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])
            ->assertOk()
            ->assertJsonPath('redirect_url', '/client/bookings');

        $this->assertFalse($user->fresh()->must_set_password);
    }

    public function test_dates_that_are_taken_leave_no_account_behind(): void
    {
        $property = Property::factory()->create(['max_guests' => 4]);
        $checkIn = today()->addDays(5);

        Booking::factory()->create([
            'property_id' => $property->id,
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->copy()->addDays(3)->toDateString(),
            'status' => Booking::STATUS_CONFIRMED,
        ]);

        $this->postJson(route('client.auth.start'), [
            'name' => 'Late Guest',
            'email' => 'late@example.com',
            'reservation' => [
                'property_id' => $property->id,
                'check_in' => $checkIn->toDateString(),
                'check_out' => $checkIn->copy()->addDays(2)->toDateString(),
                'guests' => 2,
            ],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'late@example.com']);
        $this->assertGuest();
    }

    public function test_the_password_page_is_only_for_accounts_still_waiting_for_one(): void
    {
        $this->get(route('client.auth.password'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create());

        $this->get(route('client.auth.password'))->assertRedirect(route('client.bookings'));
    }

    public function test_the_password_step_cannot_replace_a_password_already_chosen(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);
        $original = $user->password;

        $this->actingAs($user)
            ->postJson(route('client.auth.password.store'), [
                'password' => 'a-new-strong-password',
                'password_confirmation' => 'a-new-strong-password',
            ])
            ->assertStatus(422);

        $this->assertSame($original, $user->fresh()->password);
    }

    public function test_the_verification_code_step_no_longer_exists(): void
    {
        $this->assertFalse(
            app('router')->has('client.auth.verify'),
            'The guest booking flow must not route through a verification code.',
        );
    }

    public function test_a_client_logs_in_with_a_password_and_reserves_in_one_step(): void
    {
        $user = User::factory()->create([
            'email' => 'guest@example.com',
            'password' => Hash::make('correct-password'),
        ]);
        $property = Property::factory()->create(['price_per_night' => 100, 'max_guests' => 4]);

        $reservation = [
            'property_id' => $property->id,
            'check_in' => today()->addDays(4)->toDateString(),
            'check_out' => today()->addDays(6)->toDateString(),
            'guests' => 2,
        ];

        $this->postJson(route('client.auth.login'), [
            'email' => 'guest@example.com',
            'password' => 'correct-password',
            'reservation' => $reservation,
        ])
            ->assertOk()
            ->assertJsonPath('redirect_url', '/client/bookings');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('bookings', [
            'client_id' => $user->id,
            'property_id' => $property->id,
            'total_price' => 200,
        ]);
    }

    public function test_a_wrong_password_does_not_authenticate(): void
    {
        User::factory()->create([
            'email' => 'guest@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson(route('client.auth.login'), [
            'email' => 'guest@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertGuest();
    }
}
