<?php

namespace Tests\Feature\Domains\Access;

use App\Domains\Access\Models\Role;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class RolesPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
    }

    /**
     * Create a user whose membership in their current team has the given
     * Access role assigned explicitly (bypassing the legacy fallback).
     */
    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create();

        $this->assignRole($user, $user->currentTeam, $roleCode);

        return $user;
    }

    private function assignRole(User $user, Team $team, string $roleCode): Membership
    {
        $membership = Membership::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $membership->update([
            'role' => 'member',
            'role_id' => Role::where('code', $roleCode)->firstOrFail()->id,
        ]);

        return $membership;
    }

    public function test_index_renders_roles_permissions_and_members(): void
    {
        $user = $this->userWithRole('tenant_admin');

        $response = $this->actingAs($user)->get(
            route('access.roles.index', ['current_team' => $user->currentTeam->slug]),
        );

        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/roles/index')
            ->has('roles')
            ->has('permissions')
            ->has('members', 1, fn (AssertableInertia $member) => $member
                ->where('userName', $user->name)
                ->where('userEmail', $user->email)
                ->where('roleCode', 'tenant_admin')
                ->etc()
            )
        );
    }

    public function test_index_is_forbidden_without_users_view_permission(): void
    {
        $user = $this->userWithRole('billing_manager');

        $this->actingAs($user)
            ->get(route('access.roles.index', ['current_team' => $user->currentTeam->slug]))
            ->assertForbidden();
    }

    public function test_index_allows_readonly_viewer(): void
    {
        $user = $this->userWithRole('viewer');

        $this->actingAs($user)
            ->get(route('access.roles.index', ['current_team' => $user->currentTeam->slug]))
            ->assertOk();
    }

    public function test_store_is_forbidden_without_users_manage(): void
    {
        $user = $this->userWithRole('viewer');

        $this->actingAs($user)
            ->post(route('access.roles.store', ['current_team' => $user->currentTeam->slug]), [
                'name' => 'Night Shift',
                'code' => 'night_shift',
                'permissions' => ['incidents.view'],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('roles', ['code' => 'night_shift']);
    }

    public function test_update_syncs_permissions_on_custom_role(): void
    {
        $user = $this->userWithRole('tenant_admin');
        $role = Role::factory()->create(['code' => 'custom_ops', 'is_system' => false]);

        $response = $this->actingAs($user)->put(
            route('access.roles.update', [
                'current_team' => $user->currentTeam->slug,
                'role' => $role->id,
            ]),
            [
                'name' => 'Renamed Ops',
                'description' => 'Updated description',
                'permissions' => ['incidents.view', 'incidents.manage'],
            ],
        );

        $response->assertRedirect();

        $role->refresh();
        $this->assertSame('Renamed Ops', $role->name);
        $this->assertEqualsCanonicalizing(
            ['incidents.view', 'incidents.manage'],
            $role->permissions()->pluck('code')->all(),
        );
    }

    public function test_update_cannot_rename_system_role(): void
    {
        $user = $this->userWithRole('tenant_admin');
        $role = Role::where('code', 'viewer')->firstOrFail();

        $this->actingAs($user)
            ->put(route('access.roles.update', [
                'current_team' => $user->currentTeam->slug,
                'role' => $role->id,
            ]), [
                'name' => 'Renamed Viewer',
                'permissions' => ['incidents.view'],
            ])
            ->assertForbidden();
    }

    public function test_update_can_sync_permissions_of_system_role_without_name(): void
    {
        $user = $this->userWithRole('tenant_admin');
        $role = Role::where('code', 'viewer')->firstOrFail();

        $this->actingAs($user)
            ->put(route('access.roles.update', [
                'current_team' => $user->currentTeam->slug,
                'role' => $role->id,
            ]), [
                'permissions' => ['incidents.view', 'audit.view'],
            ])
            ->assertRedirect();

        $this->assertEqualsCanonicalizing(
            ['incidents.view', 'audit.view'],
            $role->permissions()->pluck('code')->all(),
        );
    }

    public function test_destroy_deletes_custom_role(): void
    {
        $user = $this->userWithRole('tenant_admin');
        $role = Role::factory()->create(['code' => 'disposable', 'is_system' => false]);

        $this->actingAs($user)
            ->delete(route('access.roles.destroy', [
                'current_team' => $user->currentTeam->slug,
                'role' => $role->id,
            ]))
            ->assertRedirect();

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_destroy_is_forbidden_without_users_manage(): void
    {
        $user = $this->userWithRole('viewer');
        $role = Role::factory()->create(['code' => 'disposable', 'is_system' => false]);

        $this->actingAs($user)
            ->delete(route('access.roles.destroy', [
                'current_team' => $user->currentTeam->slug,
                'role' => $role->id,
            ]))
            ->assertForbidden();

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_member_role_update_assigns_role(): void
    {
        $admin = $this->userWithRole('tenant_admin');
        $team = $admin->currentTeam;

        $colleague = User::factory()->create();
        $team->members()->attach($colleague, ['role' => 'member']);
        $membership = Membership::where('team_id', $team->id)
            ->where('user_id', $colleague->id)
            ->firstOrFail();

        $this->actingAs($admin)
            ->put(route('access.members.role.update', [
                'current_team' => $team->slug,
                'membership' => $membership->id,
            ]), ['role_code' => 'supervisor'])
            ->assertRedirect();

        $membership->refresh();
        $this->assertSame(
            Role::where('code', 'supervisor')->firstOrFail()->id,
            $membership->role_id,
        );
    }

    public function test_member_role_update_is_forbidden_without_users_manage(): void
    {
        $user = $this->userWithRole('viewer');
        $team = $user->currentTeam;
        $membership = Membership::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->actingAs($user)
            ->put(route('access.members.role.update', [
                'current_team' => $team->slug,
                'membership' => $membership->id,
            ]), ['role_code' => 'supervisor'])
            ->assertForbidden();
    }

    public function test_member_role_update_rejects_membership_of_another_team(): void
    {
        $admin = $this->userWithRole('tenant_admin');

        $stranger = User::factory()->create();
        $foreignMembership = Membership::where('team_id', $stranger->currentTeam->id)
            ->where('user_id', $stranger->id)
            ->firstOrFail();

        $this->actingAs($admin)
            ->put(route('access.members.role.update', [
                'current_team' => $admin->currentTeam->slug,
                'membership' => $foreignMembership->id,
            ]), ['role_code' => 'supervisor'])
            ->assertNotFound();

        $this->assertNull($foreignMembership->fresh()->role_id);
    }
}
