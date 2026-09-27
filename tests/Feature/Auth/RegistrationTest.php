<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Decisión de producto: sólo el super-admin crea tenants y usuarios dueños.
 * El auto-registro público está cerrado — /register no existe.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('register'));
        $this->assertFalse(Route::has('register.store'));
    }

    public function test_registration_screen_returns_not_found(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_posting_to_register_does_not_create_a_user(): void
    {
        $this->post('/register', [
            'name' => 'Intruso',
            'email' => 'victima@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'victima@example.com']);
    }

    public function test_login_page_does_not_offer_registration(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('auth/login')
                ->missing('canRegister'));
    }

    public function test_user_emails_are_stored_lowercase(): void
    {
        $user = User::factory()->create(['email' => '  Mixed.Case@Example.COM ']);

        $this->assertSame('mixed.case@example.com', $user->fresh()->email);
    }
}
