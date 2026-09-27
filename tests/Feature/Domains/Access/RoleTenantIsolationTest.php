<?php

namespace Tests\Feature\Domains\Access;

use App\Domains\Access\Actions\SyncRolePermissions;
use App\Domains\Access\Models\Role;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Los roles personalizados son de un tenant y los de sistema son catálogo de
 * plataforma: un tenant no ve, no edita ni borra los roles de otro, ni puede
 * cambiar los permisos de un rol de sistema (eso cambiaría a TODOS los tenants).
 */
class RoleTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $adminA;

    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        // Dueños de su equipo personal → tenant_admin vía fallback legado.
        $this->adminA = User::factory()->create();
        $this->adminB = User::factory()->create();
    }

    private function customRoleOf(User $admin, string $slug = 'turno-noche'): Role
    {
        $team = $admin->currentTeam;

        $role = Role::factory()->create([
            'team_id' => $team->id,
            'code' => Role::customCodeFor($team->id, $slug),
            'is_system' => false,
        ]);

        app(SyncRolePermissions::class)->execute($role, ['incidents.view']);

        return $role;
    }

    public function test_index_lists_system_roles_and_only_own_custom_roles(): void
    {
        $roleA = $this->customRoleOf($this->adminA);
        $roleB = $this->customRoleOf($this->adminB);

        $this->actingAs($this->adminA)
            ->get(route('access.roles.index', ['current_team' => $this->adminA->currentTeam->slug]))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($roleA, $roleB) {
                $page->component('settings/roles/index');

                $roles = collect($page->toArray()['props']['roles'])->keyBy('id');
                $viewerId = Role::where('code', 'viewer')->value('id');

                $this->assertTrue($roles->has($roleA->id));
                $this->assertFalse($roles->has($roleB->id));
                $this->assertTrue($roles->has($viewerId));

                // Sólo los roles propios son editables; los de sistema, no.
                $this->assertTrue($roles[$roleA->id]['editable']);
                $this->assertFalse($roles[$viewerId]['editable']);

                // El propio usuario (propietario) aparece bloqueado.
                $this->assertTrue(collect($page->toArray()['props']['members'])->every(fn ($m) => $m['locked']));
            });
    }

    public function test_tenant_cannot_update_another_tenants_role(): void
    {
        $roleB = $this->customRoleOf($this->adminB);

        $this->actingAs($this->adminA)
            ->put(route('access.roles.update', [
                'current_team' => $this->adminA->currentTeam->slug,
                'role' => $roleB->id,
            ]), ['name' => 'Hackeado', 'permissions' => ['users.manage']])
            ->assertNotFound();

        $this->assertNotSame('Hackeado', $roleB->fresh()->name);
        $this->assertSame(['incidents.view'], $roleB->permissions()->pluck('code')->all());
    }

    public function test_tenant_cannot_delete_another_tenants_role(): void
    {
        $roleB = $this->customRoleOf($this->adminB);

        $this->actingAs($this->adminA)
            ->delete(route('access.roles.destroy', [
                'current_team' => $this->adminA->currentTeam->slug,
                'role' => $roleB->id,
            ]))
            ->assertNotFound();

        $this->assertDatabaseHas('roles', ['id' => $roleB->id]);
    }

    public function test_tenant_cannot_change_system_role_permissions(): void
    {
        $viewer = Role::where('code', 'viewer')->firstOrFail();
        $before = $viewer->permissions()->pluck('code')->sort()->values()->all();

        $this->actingAs($this->adminA)
            ->put(route('access.roles.update', [
                'current_team' => $this->adminA->currentTeam->slug,
                'role' => $viewer->id,
            ]), ['permissions' => ['incidents.view', 'users.manage']])
            ->assertForbidden();

        $this->assertSame($before, $viewer->permissions()->pluck('code')->sort()->values()->all());
    }

    public function test_tenant_cannot_assign_another_tenants_role(): void
    {
        $roleB = $this->customRoleOf($this->adminB);
        $team = $this->adminA->currentTeam;

        $colleague = User::factory()->create();
        $team->members()->attach($colleague, ['role' => 'member']);
        $membership = Membership::where('team_id', $team->id)->where('user_id', $colleague->id)->firstOrFail();

        $this->actingAs($this->adminA)
            ->put(route('access.members.role.update', [
                'current_team' => $team->slug,
                'membership' => $membership->id,
            ]), ['role_code' => $roleB->code])
            ->assertSessionHasErrors('role_code');

        $this->assertNull($membership->fresh()->role_id);
    }

    public function test_custom_role_is_created_in_the_current_team_with_namespaced_code(): void
    {
        $teamA = $this->adminA->currentTeam;
        $teamB = $this->adminB->currentTeam;

        foreach ([[$this->adminA, $teamA], [$this->adminB, $teamB]] as [$admin, $team]) {
            $this->actingAs($admin)
                ->post(route('access.roles.store', ['current_team' => $team->slug]), [
                    'name' => 'Turno noche',
                    'code' => 'turno-noche',
                    'permissions' => ['incidents.view'],
                ])
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseHas('roles', ['team_id' => $teamA->id, 'code' => "t{$teamA->id}-turno-noche", 'is_system' => false]);
        $this->assertDatabaseHas('roles', ['team_id' => $teamB->id, 'code' => "t{$teamB->id}-turno-noche", 'is_system' => false]);

        // Mismo slug dos veces en el mismo tenant → error de validación.
        $this->actingAs($this->adminA)
            ->post(route('access.roles.store', ['current_team' => $teamA->slug]), [
                'name' => 'Otra vez',
                'code' => 'turno-noche',
                'permissions' => ['incidents.view'],
            ])
            ->assertSessionHasErrors('code');
    }

    public function test_super_admin_can_still_tune_system_roles(): void
    {
        $operator = User::factory()->create(['global_role' => 'super_admin']);
        $viewer = Role::where('code', 'viewer')->firstOrFail();

        $this->actingAs($operator)
            ->put(route('access.roles.update', [
                'current_team' => $operator->currentTeam->slug,
                'role' => $viewer->id,
            ]), ['permissions' => ['incidents.view', 'audit.view']])
            ->assertRedirect();

        $this->assertEqualsCanonicalizing(['incidents.view', 'audit.view'], $viewer->permissions()->pluck('code')->all());
    }

    public function test_deleting_a_team_deletes_its_custom_roles(): void
    {
        $team = Team::factory()->create();
        $role = Role::factory()->create(['team_id' => $team->id]);

        $team->forceDelete();

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }
}
