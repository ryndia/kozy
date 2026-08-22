<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class LoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_clients_are_redirected_to_the_client_dashboard(): void
    {
        $client = User::factory()->create([
            'role' => 'user',
            'otp_verified_at' => now(),
        ]);

        $this->post(route('login.store'), [
            'email' => $client->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_admins_are_redirected_to_the_filament_panel(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'otp_verified_at' => now(),
        ]);

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(url('/admin'));
    }

    /**
     * Inertia cannot follow a redirect to the Filament panel: the plain HTML
     * response ends up in Inertia's error dialog, showing the panel in a
     * floating window. The 409 + X-Inertia-Location pair forces a full visit.
     */
    public function test_an_inertia_login_leaves_the_spa_for_the_panel(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'otp_verified_at' => now(),
        ]);

        $response = $this->withHeaders($this->inertiaHeaders())->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location', url('/admin'));
    }

    public function test_an_inertia_login_stays_in_the_spa_for_clients(): void
    {
        $client = User::factory()->create([
            'role' => 'user',
            'otp_verified_at' => now(),
        ]);

        $this->withHeaders($this->inertiaHeaders())->post(route('login.store'), [
            'email' => $client->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_an_admin_returns_to_the_panel_page_they_asked_for(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'otp_verified_at' => now(),
        ]);

        $this->withSession(['url.intended' => url('/admin/properties')])
            ->post(route('login.store'), [
                'email' => $admin->email,
                'password' => 'password',
            ])->assertRedirect(url('/admin/properties'));
    }

    public function test_a_client_is_never_sent_into_the_panel(): void
    {
        $client = User::factory()->create([
            'role' => 'user',
            'otp_verified_at' => now(),
        ]);

        $this->withSession(['url.intended' => url('/admin/properties')])
            ->post(route('login.store'), [
                'email' => $client->email,
                'password' => 'password',
            ])->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_login_does_not_follow_an_intended_url_off_site(): void
    {
        $client = User::factory()->create([
            'role' => 'user',
            'otp_verified_at' => now(),
        ]);

        $this->withSession(['url.intended' => 'https://evil.example.com/steal'])
            ->post(route('login.store'), [
                'email' => $client->email,
                'password' => 'password',
            ])->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_login_does_not_bounce_back_to_the_login_screen(): void
    {
        $client = User::factory()->create([
            'role' => 'user',
            'otp_verified_at' => now(),
        ]);

        $this->withSession(['url.intended' => url('/login')])
            ->post(route('login.store'), [
                'email' => $client->email,
                'password' => 'password',
            ])->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_renters_reach_the_client_dashboard_after_verifying_their_code(): void
    {
        $renter = User::factory()->create([
            'role' => 'renter',
            'otp_verified_at' => null,
        ]);

        $this->actingAs($renter)
            ->withSession([
                'otp_code' => '123456',
                'otp_expires_at' => now()->addMinutes(5)->timestamp,
            ])
            ->post(route('otp.verify.store'), ['otp' => '123456'])
            ->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_a_signed_in_admin_visiting_the_login_page_lands_in_the_panel(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'otp_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('login'))
            ->assertRedirect(url('/admin'));
    }

    public function test_a_signed_in_admin_visiting_the_login_page_from_inertia_gets_a_full_visit(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'otp_verified_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->withHeaders($this->inertiaHeaders())
            ->get(route('login'));

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location', url('/admin'));
    }

    public function test_a_signed_in_client_visiting_the_login_page_lands_on_the_dashboard(): void
    {
        $client = User::factory()->create([
            'role' => 'user',
            'otp_verified_at' => now(),
        ]);

        $this->actingAs($client)
            ->get(route('login'))
            ->assertRedirect(route('dashboard', absolute: false));
    }

    /**
     * Headers that make a request look like a visit from the Inertia client,
     * including the asset version so the middleware does not force a refresh.
     *
     * @return array<string, string>
     */
    private function inertiaHeaders(): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
        ];
    }
}
