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
use App\Domains\Drivers\Models\DriverDocument;
use App\Domains\Drivers\Models\DriverRiskProfile;
use App\Domains\Drivers\Models\DriverStatusLog;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DriverShowPageTest extends TestCase
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
        $driver = Driver::factory()->create(['team_id' => $user->currentTeam->id]);

        $response = $this->get(
            route('drivers.show', [
                'current_team' => $user->currentTeam->slug,
                'driver' => $driver->id,
            ]),
        );

        $response->assertRedirect(route('login'));
    }

    public function test_member_without_drivers_view_gets_403(): void
    {
        [$user, $team] = $this->createUserWithRole('detailless', []);
        $driver = Driver::factory()->create(['team_id' => $team->id]);

        $response = $this->actingAs($user)->get(
            route('drivers.show', [
                'current_team' => $team->slug,
                'driver' => $driver->id,
            ]),
        );

        $response->assertForbidden();
    }

    public function test_driver_of_another_team_returns_404(): void
    {
        [$user, $team] = $this->createUserWithRole('detail_viewer_x', ['drivers.view']);

        $foreignOwner = User::factory()->create();
        $foreignDriver = Driver::factory()->create([
            'team_id' => $foreignOwner->currentTeam->id,
        ]);

        $response = $this->actingAs($user)->get(
            route('drivers.show', [
                'current_team' => $team->slug,
                'driver' => $foreignDriver->id,
            ]),
        );

        $response->assertNotFound();
    }

    public function test_page_renders_full_driver_profile(): void
    {
        [$user, $team] = $this->createUserWithRole('detail_viewer', ['drivers.view']);

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

        $current = DriverAssignment::factory()->create([
            'team_id' => $team->id,
            'driver_id' => $driver->id,
            'asset_id' => $asset->id,
            'started_at' => now()->subDays(3),
            'ended_at' => null,
        ]);

        $past = DriverAssignment::factory()->create([
            'team_id' => $team->id,
            'driver_id' => $driver->id,
            'asset_id' => $asset->id,
            'started_at' => now()->subDays(30),
            'ended_at' => now()->subDays(10),
        ]);

        DriverRiskProfile::factory()->create([
            'driver_id' => $driver->id,
            'risk_score' => 72.5,
            'incidents_count' => 3,
        ]);

        DriverContact::factory()->primary()->create([
            'driver_id' => $driver->id,
            'value' => '+52 81 1234 5678',
        ]);

        $document = DriverDocument::factory()->create([
            'driver_id' => $driver->id,
            'document_number' => 'LIC-12345',
        ]);

        DriverStatusLog::factory()->create([
            'driver_id' => $driver->id,
            'status_code' => 'active',
            'effective_from' => now()->subDay(),
        ]);

        $response = $this->actingAs($user)->get(
            route('drivers.show', [
                'current_team' => $team->slug,
                'driver' => $driver->id,
            ]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('drivers/show')
                ->has(
                    'driver',
                    fn (Assert $detail) => $detail
                        ->where('id', $driver->id)
                        ->where('fullName', 'Ana Torres')
                        ->where('employeeCode', 'EMP-0042')
                        ->where('status', 'active')
                        ->where('currentAsset.id', $asset->id)
                        ->where('riskProfile.riskScore', 72.5)
                        ->where('riskProfile.incidentsCount', 3)
                        ->has('contacts', 1)
                        ->where('contacts.0.value', '+52 81 1234 5678')
                        ->where('contacts.0.isPrimary', true)
                        ->has('documents', 1)
                        ->where('documents.0.documentNumber', 'LIC-12345')
                        ->where('documents.0.id', $document->id)
                        ->etc(),
                )
                ->has('assignments', 2)
                ->where('assignments.0.id', $current->id)
                ->where('assignments.0.isCurrent', true)
                ->where('assignments.1.id', $past->id)
                ->where('assignments.1.isCurrent', false)
                ->has('statusLog', 1)
                ->where('statusLog.0.statusCode', 'active'),
        );
    }

    public function test_identity_is_exposed_for_a_driver_without_operational_history(): void
    {
        // B4: un conductor recién sincronizado (sin riesgo/contactos/documentos/
        // asignaciones/estado) debe exponer igualmente su identidad.
        [$user, $team] = $this->createUserWithRole('detail_identity', ['drivers.view']);

        $driver = Driver::factory()->create([
            'team_id' => $team->id,
            'full_name' => 'Bruno Salas',
            'employee_code' => 'EMP-0099',
            'external_primary_id' => 'SAMSARA-555',
        ]);

        $response = $this->actingAs($user)->get(
            route('drivers.show', [
                'current_team' => $team->slug,
                'driver' => $driver->id,
            ]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('drivers/show')
                ->where('driver.fullName', 'Bruno Salas')
                ->where('driver.employeeCode', 'EMP-0099')
                ->where('driver.externalPrimaryId', 'SAMSARA-555')
                ->where('driver.riskProfile', null)
                ->has('driver.contacts', 0)
                ->has('assignments', 0)
                ->has('statusLog', 0),
        );
    }

    public function test_related_records_of_other_drivers_are_not_leaked(): void
    {
        [$user, $team] = $this->createUserWithRole('detail_viewer_2', ['drivers.view']);

        $driver = Driver::factory()->create(['team_id' => $team->id]);

        $other = Driver::factory()->create(['team_id' => $team->id]);
        DriverContact::factory()->create(['driver_id' => $other->id]);
        DriverStatusLog::factory()->create(['driver_id' => $other->id]);

        $response = $this->actingAs($user)->get(
            route('drivers.show', [
                'current_team' => $team->slug,
                'driver' => $driver->id,
            ]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('drivers/show')
                ->has('driver.contacts', 0)
                ->has('assignments', 0)
                ->has('statusLog', 0),
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
