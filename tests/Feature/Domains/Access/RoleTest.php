<?php

namespace Tests\Feature\Domains\Access;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Role;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Roles + permissions baseline. Team owners fall back to the
        // tenant_admin role (users.manage) via AuthorizeAction's legacy map.
        $this->seed(AccessSeeder::class);
    }

    public function test_system_roles_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $role = Role::where('code', 'tenant_admin')->firstOrFail();

        $response = $this->actingAs($user)->delete(
            route('access.roles.destroy', ['current_team' => $team->slug, 'role' => $role->id]),
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
        $this->assertNotNull(
            Role::find($role->id),
            'System role should NOT be deleted from the database after a forbidden delete attempt',
        );
    }

    public function test_custom_role_can_be_created_per_tenant(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $response = $this->actingAs($user)->post(
            route('access.roles.store', ['current_team' => $team->slug]),
            [
                'name' => 'Night Shift',
                'code' => 'night_shift',
                'description' => 'Night shift operators',
                'permissions' => ['incidents.view'],
            ],
        );

        $response->assertRedirect();

        $this->assertDatabaseHas('roles', [
            'name' => 'Night Shift',
            'code' => 'night_shift',
            'scope' => RoleScope::Tenant->value,
            'is_system' => false,
        ]);

        $createdRole = Role::where('code', 'night_shift')->first();

        $this->assertNotNull(
            $createdRole,
            'Custom tenant-scoped role should be created in the database',
        );

        $this->assertTrue(
            $createdRole->permissions()->where('code', 'incidents.view')->exists(),
            'Custom role should have the assigned permissions',
        );
    }
}
