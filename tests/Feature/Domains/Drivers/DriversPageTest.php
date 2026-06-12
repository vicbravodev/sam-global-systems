<?php

namespace Tests\Feature\Domains\Drivers;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Enums\DriverStatus;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverAssignment;
use App\Domains\Drivers\Models\DriverContact;
use App\Domains\Drivers\Models\DriverRiskProfile;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DriversPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $user = User::factory()->create();

        $response = $this->get(
            route('drivers.index', ['current_team' => $user->currentTeam->slug]),
        );

        $response->assertRedirect(route('login'));
    }

    public function test_member_without_drivers_view_gets_403(): void
    {
        [$user, $team] = $this->createUserWithRole('rosterless', []);

        $response = $this->actingAs($user)->get(
            route('drivers.index', ['current_team' => $team->slug]),
        );

        $response->assertForbidden();
    }

    public function test_page_renders_drivers_with_row_shape(): void
    {
        [$user, $team] = $this->createUserWithRole('roster_viewer', ['drivers.view']);

        $driver = Driver::factory()->create([
            'team_id' => $team->id,
            'full_name' => 'Ana Torres',
            'employee_code' => 'EMP-0042',
            'status' => DriverStatus::Active,
            'last_seen_at' => now()->subMinutes(5),
        ]);

        $asset = Asset::factory()->create([
            'team_id' => $team->id,
            'name' => 'Camión 7',
            'code' => 'TR-007',
        ]);
        DriverAssignment::factory()->create([
            'team_id' => $team->id,
            'driver_id' => $driver->id,
            'asset_id' => $asset->id,
        ]);
        DriverRiskProfile::factory()->create([
            'driver_id' => $driver->id,
            'risk_score' => 72.5,
        ]);
        DriverContact::factory()->primary()->create([
            'driver_id' => $driver->id,
            'value' => '+52 81 1234 5678',
        ]);

        $response = $this->actingAs($user)->get(
            route('drivers.index', ['current_team' => $team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('drivers/index')
                ->has('drivers', 1)
                ->has(
                    'drivers.0',
                    fn (Assert $row) => $row
                        ->where('id', $driver->id)
                        ->where('fullName', 'Ana Torres')
                        ->where('employeeCode', 'EMP-0042')
                        ->where('status', 'active')
                        ->where('currentAsset.id', $asset->id)
                        ->where('currentAsset.name', 'Camión 7')
                        ->where('currentAsset.code', 'TR-007')
                        ->where('riskScore', 72.5)
                        ->where('phone', '+52 81 1234 5678')
                        ->etc(),
                )
                ->has('pagination')
                ->has('filters')
                ->has('filterOptions.statuses'),
        );
    }

    public function test_drivers_of_other_teams_are_not_listed(): void
    {
        [$user, $team] = $this->createUserWithRole('roster_viewer_2', ['drivers.view']);

        Driver::factory()->create(['team_id' => $team->id, 'full_name' => 'Propio']);

        $foreignOwner = User::factory()->create();
        Driver::factory()->create([
            'team_id' => $foreignOwner->currentTeam->id,
            'full_name' => 'Ajeno',
        ]);

        $response = $this->actingAs($user)->get(
            route('drivers.index', ['current_team' => $team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('drivers/index')
                ->has('drivers', 1)
                ->where('drivers.0.fullName', 'Propio'),
        );
    }

    public function test_status_filter_narrows_the_roster(): void
    {
        [$user, $team] = $this->createUserWithRole('roster_viewer_3', ['drivers.view']);

        Driver::factory()->create([
            'team_id' => $team->id,
            'status' => DriverStatus::Active,
        ]);
        Driver::factory()->create([
            'team_id' => $team->id,
            'status' => DriverStatus::Suspended,
        ]);

        $response = $this->actingAs($user)->get(
            route('drivers.index', [
                'current_team' => $team->slug,
                'status' => 'suspended',
            ]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('drivers/index')
                ->has('drivers', 1)
                ->where('drivers.0.status', 'suspended')
                ->where('filters.status', 'suspended'),
        );
    }

    public function test_column_presence_reflects_tenant_wide_data(): void
    {
        [$user, $team] = $this->createUserWithRole('roster_columns_1', ['drivers.view']);

        $driver = Driver::factory()->create([
            'team_id' => $team->id,
            'last_seen_at' => now()->subHour(),
        ]);

        $asset = Asset::factory()->create(['team_id' => $team->id]);
        DriverAssignment::factory()->create([
            'team_id' => $team->id,
            'driver_id' => $driver->id,
            'asset_id' => $asset->id,
        ]);
        DriverRiskProfile::factory()->create(['driver_id' => $driver->id]);
        DriverContact::factory()->create(['driver_id' => $driver->id]);

        $response = $this->actingAs($user)->get(
            route('drivers.index', ['current_team' => $team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('drivers/index')
                ->where('columns.asset', true)
                ->where('columns.risk', true)
                ->where('columns.phone', true)
                ->where('columns.lastSeen', true),
        );
    }

    public function test_column_presence_is_false_when_no_driver_of_the_tenant_has_data(): void
    {
        [$user, $team] = $this->createUserWithRole('roster_columns_2', ['drivers.view']);

        Driver::factory()->create([
            'team_id' => $team->id,
            'last_seen_at' => null,
        ]);

        // Data on ANOTHER tenant must not switch the columns on here.
        $foreignOwner = User::factory()->create();
        $foreignDriver = Driver::factory()->create([
            'team_id' => $foreignOwner->currentTeam->id,
            'last_seen_at' => now(),
        ]);
        DriverContact::factory()->create(['driver_id' => $foreignDriver->id]);
        DriverRiskProfile::factory()->create(['driver_id' => $foreignDriver->id]);

        $response = $this->actingAs($user)->get(
            route('drivers.index', ['current_team' => $team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('drivers/index')
                ->where('columns.asset', false)
                ->where('columns.risk', false)
                ->where('columns.phone', false)
                ->where('columns.lastSeen', false),
        );
    }

    public function test_column_presence_ignores_active_filters(): void
    {
        [$user, $team] = $this->createUserWithRole('roster_columns_3', ['drivers.view']);

        $driver = Driver::factory()->create([
            'team_id' => $team->id,
            'status' => DriverStatus::Active,
            'last_seen_at' => now(),
        ]);
        DriverContact::factory()->create(['driver_id' => $driver->id]);

        // The filter excludes every driver, but the presence map stays
        // tenant-wide so the layout does not jump when filters change.
        $response = $this->actingAs($user)->get(
            route('drivers.index', [
                'current_team' => $team->slug,
                'status' => 'suspended',
            ]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('drivers/index')
                ->has('drivers', 0)
                ->where('columns.phone', true)
                ->where('columns.lastSeen', true),
        );
    }

    public function test_search_filter_matches_name_case_insensitively(): void
    {
        [$user, $team] = $this->createUserWithRole('roster_viewer_4', ['drivers.view']);

        Driver::factory()->create([
            'team_id' => $team->id,
            'full_name' => 'Carlos Mendoza',
        ]);
        Driver::factory()->create([
            'team_id' => $team->id,
            'full_name' => 'Lucía Fernández',
        ]);

        $response = $this->actingAs($user)->get(
            route('drivers.index', [
                'current_team' => $team->slug,
                'q' => 'mendoza',
            ]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('drivers/index')
                ->has('drivers', 1)
                ->where('drivers.0.fullName', 'Carlos Mendoza'),
        );
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
}
