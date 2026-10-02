<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class CreateSuperAdminTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    public function test_it_creates_a_verified_super_admin_with_a_personal_team_on_an_empty_database(): void
    {
        $this->artisan('sam:create-super-admin', ['email' => 'Victor@Sam.Example', '--name' => 'Victor'])
            ->expectsQuestion('Contraseña', 'Sam-Operador-2026!x')
            ->expectsQuestion('Confirma la contraseña', 'Sam-Operador-2026!x')
            ->assertSuccessful();

        $user = User::sole();
        $this->assertSame('victor@sam.example', $user->email);
        $this->assertTrue($user->isSuperAdmin());
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('Sam-Operador-2026!x', $user->password));
        $this->assertSame($user->personalTeam()?->id, $user->current_team_id);

        $this->assertSystemLogged('tenancy.super_admin.bootstrapped');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_it_fails_when_the_confirmation_does_not_match(): void
    {
        $this->artisan('sam:create-super-admin', ['email' => 'victor@sam.example', '--name' => 'Victor'])
            ->expectsQuestion('Contraseña', 'Sam-Operador-2026!x')
            ->expectsQuestion('Confirma la contraseña', 'otra-cosa')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_promotes_an_existing_user_without_touching_the_password(): void
    {
        $user = User::factory()->create(['email' => 'victor@sam.example', 'global_role' => null]);
        $hash = $user->password;

        $this->artisan('sam:create-super-admin', ['email' => 'victor@sam.example'])->assertSuccessful();

        $user->refresh();
        $this->assertTrue($user->isSuperAdmin());
        $this->assertSame($hash, $user->password);
        $this->assertNotNull($user->personalTeam());
    }

    public function test_it_rejects_an_invalid_email(): void
    {
        $this->artisan('sam:create-super-admin', ['email' => 'no-es-email'])->assertFailed();

        $this->assertSame(0, User::count());
    }
}
