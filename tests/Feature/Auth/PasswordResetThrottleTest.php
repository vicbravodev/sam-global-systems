<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * forgot-password y reset-password no tenían throttle: permitían enumerar /
 * spamear correos de reset y fuerza bruta de tokens.
 */
class PasswordResetThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_is_throttled_by_ip(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('password.email'), ['email' => $user->email])->assertStatus(302);
        }

        $this->post(route('password.email'), ['email' => 'otro@example.com'])->assertStatus(429);
    }

    public function test_reset_password_is_throttled_by_ip(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('password.update'), [
                'token' => 'bad-token-'.$i,
                'email' => $user->email,
                'password' => 'nueva-clave-segura-1',
                'password_confirmation' => 'nueva-clave-segura-1',
            ])->assertStatus(302);
        }

        $this->post(route('password.update'), [
            'token' => 'bad-token-x',
            'email' => $user->email,
            'password' => 'nueva-clave-segura-1',
            'password_confirmation' => 'nueva-clave-segura-1',
        ])->assertStatus(429);
    }
}
