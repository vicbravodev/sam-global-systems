<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoteSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_grants_super_admin(): void
    {
        $user = User::factory()->create(['global_role' => null]);

        $this->artisan('sam:promote-super-admin', ['email' => $user->email])
            ->assertSuccessful();

        $this->assertSame('super_admin', $user->fresh()->global_role);
    }

    public function test_it_revokes_super_admin_with_demote_flag(): void
    {
        $user = User::factory()->create(['global_role' => 'super_admin']);

        $this->artisan('sam:promote-super-admin', [
            'email' => $user->email,
            '--demote' => true,
        ])->assertSuccessful();

        $this->assertNull($user->fresh()->global_role);
    }

    public function test_it_fails_for_unknown_email(): void
    {
        $this->artisan('sam:promote-super-admin', ['email' => 'nobody@nowhere.test'])
            ->assertFailed();
    }
}
