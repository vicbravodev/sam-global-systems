<?php

namespace Tests\Feature\Domains\Access;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InertiaPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_inertia_props_include_permissions(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $role = Role::factory()->create([
            'code' => 'inertia_test_role',
            'scope' => RoleScope::Tenant,
        ]);

        $permission = Permission::factory()->create([
            'code' => 'incidents.view',
            'module' => 'incidents',
        ]);

        $role->permissions()->attach($permission);

        $membership = $user->teamMemberships()->where('team_id', $team->id)->first();
        $membership->update(['role_id' => $role->id]);

        $response = $this->actingAs($user)->get(
            route('dashboard', ['current_team' => $team->slug]),
        );

        $response->assertOk();

        $response->assertInertia(function ($page) {
            $page->has('auth.permissions');

            $permissions = $page->toArray()['props']['auth']['permissions'];

            $this->assertContains(
                'incidents.view',
                $permissions,
                'Inertia shared auth.permissions should include the user\'s permissions for the current team',
            );
        });
    }
}
