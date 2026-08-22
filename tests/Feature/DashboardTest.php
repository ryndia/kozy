<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_home_page_exposes_a_csrf_token_for_stateful_api_requests()
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('name="csrf-token"', false);
    }

    public function test_authenticated_users_can_access_protected_api_routes()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')->get('/api/auth/user');

        $response->assertOk();
    }

    public function test_admin_users_can_visit_the_admin_panel()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $response = $this->get('/admin');
        $response->assertOk();
    }

    public function test_admin_users_can_visit_the_property_images_admin_page()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $response = $this->get('/admin/property-images');
        $response->assertOk();
    }
}
