<?php

namespace Tests\Feature\Http;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NavPermissionsSharedPropTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    public function test_nav_map_reflects_the_role_permissions(): void
    {
        [$user, $team] = $this->createUserWithRole('ops_only', ['incidents.view', 'drivers.view']);

        $this->actingAs($user)
            ->get(route('dashboard', ['current_team' => $team->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('nav.incidents', true)
                ->where('nav.drivers', true)
                ->where('nav.billing', false)
                ->where('nav.audit', false)
                ->where('nav.rules', false)
                ->where('nav.analytics', false)
                ->where('nav.tenantConfig', false)
                ->where('nav.roles', false)
            );
    }

    public function test_every_section_marked_visible_actually_opens(): void
    {
        [$user, $team] = $this->createUserWithRole('ops_only', ['incidents.view', 'audit.view']);

        $this->actingAs($user)
            ->get(route('incidents.index', ['current_team' => $team->slug]))
            ->assertOk();
        $this->actingAs($user)
            ->get(route('audit.show', ['current_team' => $team->slug]))
            ->assertOk();
        $this->actingAs($user)
            ->get(route('billing.show', ['current_team' => $team->slug]))
            ->assertForbidden();
    }

    public function test_guests_get_no_nav_map(): void
    {
        $this->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page->where('nav', null));
    }

    /**
     * @param  list<string>  $permissionCodes
     * @return array{0: User, 1: Team}
     */
    private function createUserWithRole(string $roleCode, array $permissionCodes): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $role = Role::factory()->create([
            'code' => $roleCode,
            'scope' => RoleScope::Tenant,
        ]);

        $permissionIds = [];
        foreach ($permissionCodes as $code) {
            $permissionIds[] = Permission::firstOrCreate(
                ['code' => $code],
                ['name' => $code, 'module' => explode('.', $code, 2)[0]],
            )->id;
        }
        $role->permissions()->sync($permissionIds);

        $team->members()->updateExistingPivot($user->id, [
            'role' => TeamRole::Member->value,
            'role_id' => $role->id,
        ]);

        return [$user, $team];
    }
}
