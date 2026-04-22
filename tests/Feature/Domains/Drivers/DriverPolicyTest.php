<?php

namespace Tests\Feature\Domains\Drivers;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Drivers\Enums\DriverStatus;
use App\Domains\Drivers\Models\Driver;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    public function test_member_without_drivers_view_cannot_list_drivers(): void
    {
        [$user, $team] = $this->createUserWithRole('custom_no_access', []);

        $response = $this->actingAs($user)->getJson("/api/{$team->slug}/drivers");

        $response->assertForbidden();
    }

    public function test_member_with_drivers_view_can_show_driver(): void
    {
        [$user, $team] = $this->createUserWithRole('custom_viewer', ['drivers.view']);
        $driver = $this->makeDriver($team);

        $response = $this->actingAs($user)->getJson("/api/{$team->slug}/drivers/{$driver->id}");

        $response->assertOk();
    }

    public function test_member_with_drivers_view_cannot_update_contacts(): void
    {
        [$user, $team] = $this->createUserWithRole('custom_viewer_2', ['drivers.view']);
        $driver = $this->makeDriver($team);

        $response = $this->actingAs($user)->putJson("/api/{$team->slug}/drivers/{$driver->id}/contacts", [
            'contacts' => [
                ['contact_type' => 'mobile_phone', 'value' => '+521234567890'],
            ],
        ]);

        $response->assertForbidden();
    }

    public function test_member_with_drivers_manage_can_update_contacts(): void
    {
        [$user, $team] = $this->createUserWithRole('custom_manager', ['drivers.view', 'drivers.manage']);
        $driver = $this->makeDriver($team);

        $response = $this->actingAs($user)->putJson("/api/{$team->slug}/drivers/{$driver->id}/contacts", [
            'contacts' => [
                ['contact_type' => 'mobile_phone', 'value' => '+521234567890'],
            ],
        ]);

        $response->assertOk();
    }

    public function test_member_with_drivers_manage_can_update_documents(): void
    {
        [$user, $team] = $this->createUserWithRole('custom_manager_2', ['drivers.view', 'drivers.manage']);
        $driver = $this->makeDriver($team);

        $response = $this->actingAs($user)->putJson("/api/{$team->slug}/drivers/{$driver->id}/documents", [
            'documents' => [
                ['document_type' => 'license', 'document_number' => 'L-12345'],
            ],
        ]);

        $response->assertOk();
    }

    public function test_cross_tenant_driver_returns_404_even_with_permission(): void
    {
        [$user, $team] = $this->createUserWithRole('custom_cross_mgr', ['drivers.view', 'drivers.manage']);

        $foreignOwner = User::factory()->create();
        $foreignTeam = $foreignOwner->currentTeam;
        $foreignDriver = $this->makeDriver($foreignTeam);

        $response = $this->actingAs($user)->getJson("/api/{$team->slug}/drivers/{$foreignDriver->id}");

        // El BelongsToTenant scope hace que el driver no sea visible → 404 por route model binding.
        // Aunque el usuario tenga drivers.manage, no puede alcanzar recursos de otro tenant.
        $response->assertNotFound();
    }

    /**
     * @param  array<string>  $permissionCodes
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
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                [
                    'name' => ucfirst(str_replace('.', ' ', $code)),
                    'module' => explode('.', $code, 2)[0],
                ],
            );
            $permissionIds[] = $permission->id;
        }
        $role->permissions()->sync($permissionIds);

        $team->members()->updateExistingPivot($user->id, [
            'role' => TeamRole::Member->value,
            'role_id' => $role->id,
        ]);

        return [$user, $team];
    }

    private function makeDriver(Team $team): Driver
    {
        return Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'Test',
            'last_name' => 'Driver',
            'full_name' => 'Test Driver',
            'status' => DriverStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
