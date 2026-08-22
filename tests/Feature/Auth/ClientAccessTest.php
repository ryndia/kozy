<?php

namespace Tests\Feature\Auth;

use App\Models\Property;
use App\Models\User;
use App\Notifications\RenterOtpNotification;
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

    public function test_an_existing_email_is_asked_for_its_password_instead_of_an_otp(): void
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

    public function test_a_new_guest_signs_up_with_an_otp_and_a_password(): void
    {
        Notification::fake();

        $this->postJson(route('client.auth.start'), [
            'name' => 'New Guest',
            'email' => 'new@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('account_exists', false);

        Notification::assertSentOnDemand(RenterOtpNotification::class);

        $otp = session('client_auth_flow.otp');

        $this->postJson(route('client.auth.verify'), ['otp' => $otp])->assertOk();
        $this->get(route('client.auth.password'))->assertOk();

        $this->postJson(route('client.auth.password.store'), [
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])
            ->assertOk()
            ->assertJsonPath('redirect_url', '/client/bookings');

        $user = User::query()->where('email', 'new@example.com')->firstOrFail();

        $this->assertSame('user', $user->role);
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_guest_signing_up_from_a_property_page_gets_their_reservation(): void
    {
        Notification::fake();

        $property = Property::factory()->create(['price_per_night' => 150, 'max_guests' => 4]);
        $reservation = [
            'property_id' => $property->id,
            'check_in' => today()->addDays(2)->toDateString(),
            'check_out' => today()->addDays(4)->toDateString(),
            'guests' => 3,
        ];

        $this->postJson(route('client.auth.start'), [
            'name' => 'Booking Guest',
            'email' => 'booker@example.com',
            'reservation' => $reservation,
        ])->assertOk();

        $this->postJson(route('client.auth.verify'), ['otp' => session('client_auth_flow.otp')])->assertOk();

        $this->postJson(route('client.auth.password.store'), [
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])
            ->assertOk()
            ->assertJsonPath('redirect_url', '/client/bookings');

        $user = User::query()->where('email', 'booker@example.com')->firstOrFail();

        $this->assertDatabaseHas('bookings', [
            'client_id' => $user->id,
            'property_id' => $property->id,
            'guests' => 3,
            'total_price' => 300,
        ]);
    }

    public function test_an_invalid_otp_is_rejected(): void
    {
        Notification::fake();

        $this->postJson(route('client.auth.start'), [
            'name' => 'New Guest',
            'email' => 'new@example.com',
        ])->assertOk();

        $this->postJson(route('client.auth.verify'), ['otp' => '000000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('otp');

        $this->get(route('client.auth.password'))->assertRedirect(route('login'));
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
