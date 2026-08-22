<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_users_can_access_the_admin_panel(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'otp_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk();
    }

    public function test_regular_users_cannot_access_the_admin_panel(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'otp_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_renters_are_redirected_to_otp_verification_after_login(): void
    {
        $renter = User::factory()->create([
            'role' => 'renter',
            'otp_verified_at' => null,
        ]);

        $response = $this->post(route('login.store'), [
            'email' => $renter->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('otp.verify', absolute: false));
        $this->assertAuthenticatedAs($renter);
    }

    public function test_renters_need_a_valid_otp_before_accessing_the_dashboard(): void
    {
        $renter = User::factory()->create([
            'role' => 'renter',
            'otp_verified_at' => null,
        ]);

        $this->actingAs($renter)
            ->get(route('dashboard'))
            ->assertRedirect(route('otp.verify', absolute: false));

        $this->withSession([
            'otp_code' => '123456',
            'otp_expires_at' => now()->addMinutes(5)->timestamp,
        ])
            ->post(route('otp.verify.store'), [
                'otp' => '123456',
            ])
            ->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_renter_registration_assigns_the_renter_role(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Renter User',
            'email' => 'renter@example.com',
            'role' => 'renter',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect(route('otp.verify', absolute: false));
        $this->assertDatabaseHas('users', [
            'email' => 'renter@example.com',
            'role' => 'renter',
            'otp_verified_at' => null,
        ]);
    }

    public function test_admin_user_can_be_created_via_command(): void
    {
        $this->artisan('make:admin', [
            'name' => 'System Admin',
            '--email' => 'admin@example.com',
            '--password' => 'secret-password',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertDatabaseHas('users', [
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);
        $this->assertTrue($user->isAdmin());
        $this->assertFalse($user->isRenter());
        $this->assertFalse($user->requiresOtpVerification());
    }

    public function test_admin_can_open_the_property_create_page_in_filament(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'otp_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/admin/properties/create')
            ->assertOk();
    }
}
