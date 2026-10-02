<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * El ciclo de vida del 2FA y del cambio de contraseña deja su código: sólo el
 * `user_id`, nunca el email, el secreto, los códigos ni la contraseña.
 */
class TwoFactorSystemLogTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => false]);
    }

    public function test_enable_confirm_regenerate_and_disable_are_logged(): void
    {
        $user = User::factory()->create(['email' => 'ana@empresa.mx']);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->post(route('two-factor.enable'))->assertRedirect();
        $this->assertSystemLogged('auth.two_factor.enabled', fn (array $c) => $c['input']['user_id'] === $user->id && $c['result']['confirmed'] === false);

        $secret = decrypt((string) $user->fresh()->two_factor_secret);
        $code = (new Google2FA)->getCurrentOtp($secret);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->post(route('two-factor.confirm'), ['code' => $code])->assertRedirect();
        $this->assertSystemLogged('auth.two_factor.confirmed', fn (array $c) => $c['input']['user_id'] === $user->id);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->post(route('two-factor.regenerate-recovery-codes'))->assertRedirect();
        $this->assertSystemLogged('auth.two_factor.recovery_codes_generated', fn (array $c) => $c['input']['user_id'] === $user->id);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->delete(route('two-factor.disable'))->assertRedirect();
        $this->assertSystemLogged('auth.two_factor.disabled', fn (array $c) => $c['input']['user_id'] === $user->id);

        $json = (string) json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('ana@empresa.mx', $json);
        $this->assertStringNotContainsString($secret, $json);
        $this->assertStringNotContainsString($code, $json);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_login_challenge_logs_failed_and_passed_codes(): void
    {
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt((string) json_encode(['recovery-code-1', 'recovery-code-2'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);
        $this->assertSystemLogged('auth.two_factor.challenged', fn (array $c) => $c['input']['user_id'] === $user->id);

        $this->post(route('two-factor.login.store'), ['code' => '000000'])->assertRedirect();
        $this->assertSystemLogged('auth.two_factor.challenge_failed', fn (array $c) => $c['reason'] === 'invalid_code' && $c['input']['user_id'] === $user->id);

        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])->assertRedirect();
        $this->assertSystemLogged('auth.two_factor.recovery_code_used', fn (array $c) => $c['input']['user_id'] === $user->id);
        $this->assertSystemLogged('auth.two_factor.challenge_passed', fn (array $c) => $c['input']['user_id'] === $user->id);

        $json = (string) json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('recovery-code-1', $json);
        $this->assertStringNotContainsString($secret, $json);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_password_change_from_settings_is_logged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertSessionHasNoErrors();

        $ctx = $this->assertSystemLogged('auth.password.changed');
        $this->assertSame(['user_id' => $user->id], $ctx['input']);
        $this->assertTrue($ctx['result']['other_sessions_logged_out']);
        $this->assertStringNotContainsString('new-password', (string) json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }
}
