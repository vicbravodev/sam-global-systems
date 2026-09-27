<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Cambiar o restablecer la contraseña debe cerrar las demás sesiones, y
 * cambiar el email (identidad de login) exige la contraseña actual.
 */
class SessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_group_authenticates_sessions(): void
    {
        $web = app(Kernel::class)->getMiddlewareGroups()['web'] ?? [];

        $this->assertTrue(
            in_array('auth.session', $web, true) || in_array(AuthenticateSession::class, $web, true),
            'El grupo web debe incluir AuthenticateSession.',
        );
    }

    public function test_password_change_logs_out_other_devices(): void
    {
        Event::fake([OtherDeviceLogout::class]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'nueva-clave-segura-1',
                'password_confirmation' => 'nueva-clave-segura-1',
            ])
            ->assertSessionHasNoErrors();

        Event::assertDispatched(OtherDeviceLogout::class);
    }

    public function test_session_with_stale_password_hash_is_logged_out(): void
    {
        $user = User::factory()->create();
        $staleHash = Auth::guard('web')->hashPasswordForCookie($user->password);

        $user->forceFill(['password' => 'otra-clave-distinta-1'])->save();

        $this->actingAs($user->fresh())
            ->withSession(['password_hash_web' => $staleHash])
            ->get(route('profile.edit'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_password_reset_invalidates_existing_sessions(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $oldHash = Auth::guard('web')->hashPasswordForCookie($user->password);

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'reset-clave-segura-1',
                'password_confirmation' => 'reset-clave-segura-1',
            ])->assertSessionHasNoErrors();

            return true;
        });

        $this->actingAs($user->fresh())
            ->withSession(['password_hash_web' => $oldHash])
            ->get(route('profile.edit'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_changing_email_requires_current_password(): void
    {
        $user = User::factory()->create(['email' => 'actual@example.com']);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'robado@example.com',
            ])
            ->assertSessionHasErrors('current_password');

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'robado@example.com',
                'current_password' => 'incorrecta',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertSame('actual@example.com', $user->fresh()->email);
    }

    public function test_changing_only_the_name_does_not_require_password(): void
    {
        $user = User::factory()->create(['email' => 'actual@example.com']);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Nombre Nuevo',
                'email' => 'ACTUAL@example.com',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Nombre Nuevo', $user->fresh()->name);
    }
}
