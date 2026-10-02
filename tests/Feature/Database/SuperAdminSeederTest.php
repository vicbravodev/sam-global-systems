<?php

namespace Tests\Feature\Database;

use App\Models\Team;
use App\Models\User;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_a_dedicated_operator_with_admin_access(): void
    {
        $this->seed(SuperAdminSeeder::class);

        $operator = User::query()->where('email', SuperAdminSeeder::SUPER_ADMIN_EMAIL)->first();

        $this->assertNotNull($operator);
        $this->assertTrue($operator->isSuperAdmin());
        $this->assertNotNull($operator->personalTeam());
        $this->assertSame($operator->personalTeam()->id, $operator->current_team_id);

        // Sin 2FA confirmado la consola lo manda a activarlo; con 2FA entra.
        $this->actingAs($operator)
            ->get(route('admin.tenants.index'))
            ->assertRedirect(route('security.edit'));

        $operator->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($operator->fresh())
            ->get(route('admin.tenants.index'))
            ->assertOk();
    }

    public function test_the_operator_is_not_a_member_of_any_customer_tenant(): void
    {
        $customer = Team::factory()->create(['name' => 'ServiExpress JC', 'is_personal' => false]);

        $this->seed(SuperAdminSeeder::class);

        $operator = User::query()->where('email', SuperAdminSeeder::SUPER_ADMIN_EMAIL)->firstOrFail();

        $this->assertFalse($operator->belongsToTeam($customer));
        $this->assertTrue(
            $operator->teams()->where('is_personal', false)->doesntExist(),
            'The SaaS operator must not be a member of any non-personal (customer) team.',
        );
    }

    public function test_it_does_not_promote_the_tenant_admin_and_demotes_a_legacy_promotion(): void
    {
        $tenantAdmin = User::factory()->create([
            'email' => SuperAdminSeeder::LEGACY_PROMOTED_EMAIL,
            'global_role' => 'super_admin',
        ]);

        $this->seed(SuperAdminSeeder::class);

        $this->assertNull($tenantAdmin->fresh()->global_role);
        $this->assertFalse($tenantAdmin->fresh()->isSuperAdmin());

        $this->actingAs($tenantAdmin->fresh())
            ->get(route('admin.tenants.index'))
            ->assertForbidden();
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed(SuperAdminSeeder::class);
        $this->seed(SuperAdminSeeder::class);

        $this->assertSame(
            1,
            User::query()->where('email', SuperAdminSeeder::SUPER_ADMIN_EMAIL)->count(),
        );
        $this->assertSame(
            1,
            Team::query()->where('name', SuperAdminSeeder::SUPER_ADMIN_TEAM_NAME)->count(),
        );
        $this->assertTrue(
            User::query()->where('email', SuperAdminSeeder::SUPER_ADMIN_EMAIL)->firstOrFail()->isSuperAdmin(),
        );
    }
}
