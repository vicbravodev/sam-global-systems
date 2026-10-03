<?php

namespace Tests\Feature\Domains\Drivers;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Drivers\Enums\DriverStatus;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverAssignment;
use App\Domains\Drivers\Models\DriverContact;
use App\Domains\Drivers\Models\DriverDocument;
use App\Domains\Drivers\Models\DriverRiskProfile;
use App\Domains\Drivers\Models\DriverStatusLog;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class DriverShowPageTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

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
        // The card's counters are live over the 30-day window (not the
        // nightly snapshot), so the three incidents must actually exist.
        Incident::factory()->count(3)->create([
            'team_id' => $team->id,
            'driver_id' => $driver->id,
            'opened_at' => now()->subDays(2),
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
                // Sin escritor real del historial de estados: la tarjeta se
                // retiró y la página ya no expone la prop.
                ->missing('statusLog'),
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
                ->missing('statusLog'),
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
                ->missing('statusLog'),
        );
    }

    public function test_detail_exposes_activity_incidents_provider_fields_and_document_expiry(): void
    {
        [$user, $team] = $this->createUserWithRole('detail_activity', ['drivers.view']);
        $other = Team::factory()->create();

        $driver = Driver::factory()->create([
            'team_id' => $team->id,
            'metadata_json' => [
                'license_number' => 'LIC-998877',
                'license_state' => 'NL',
                'username' => 'ana.torres',
                'tags' => ['Norte', 'Turno A'],
                'internal_only' => 'never shown',
            ],
        ]);
        $asset = Asset::factory()->create(['team_id' => $team->id, 'name' => 'Camión 7']);

        DriverRiskProfile::factory()->create([
            'driver_id' => $driver->id,
            'risk_score' => 64,
            'metadata_json' => ['trend' => 'improving', 'previous_score' => 70.5, 'window_days' => 30, 'severe_events_count' => 2],
        ]);

        DriverDocument::factory()->create([
            'driver_id' => $driver->id,
            'expires_at' => now()->addDays(10)->toDateString(),
        ]);

        $recent = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'driver_id' => $driver->id,
            'asset_id' => $asset->id,
            'occurred_at' => now()->subMinutes(5),
        ]);
        NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'driver_id' => $driver->id,
            'occurred_at' => now()->subDays(2),
        ]);
        // Outside the 14-day activity window: listed in recent events but not
        // in the per-day series.
        NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'driver_id' => $driver->id,
            'occurred_at' => now()->subDays(40),
        ]);
        // Other tenant's driver id collision must never leak.
        NormalizedEvent::factory()->create([
            'team_id' => $other->id,
            'driver_id' => Driver::factory()->create(['team_id' => $other->id])->id,
        ]);

        $incident = Incident::factory()->open()->create([
            'team_id' => $team->id,
            'driver_id' => $driver->id,
            'title' => 'Pánico verificado',
        ]);
        Incident::factory()->open()->create([
            'team_id' => $other->id,
            'driver_id' => $driver->id,
        ]);

        $response = $this->actingAs($user)->get(
            route('drivers.show', ['current_team' => $team->slug, 'driver' => $driver->id]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('drivers/show')
                ->where('driver.riskProfile.trend', 'improving')
                ->where('driver.riskProfile.previousScore', 70.5)
                // Live count: the stored snapshot's 2 is ignored; the
                // factory events carry no severe event-type code.
                ->where('driver.riskProfile.severeEventsCount', 0)
                ->has('driver.providerFields', 4)
                ->where('driver.providerFields.0.key', 'license_number')
                ->where('driver.providerFields.0.value', 'LIC-998877')
                ->where('driver.providerFields.3.value', 'Norte, Turno A')
                ->where('driver.documents.0.daysToExpiry', 10)
                ->has('recentEvents', 3)
                ->where('recentEvents.0.id', $recent->id)
                ->where('recentEvents.0.asset.name', 'Camión 7')
                ->has('incidents', 1)
                ->where('incidents.0.id', $incident->id)
                ->where('incidents.0.status.name', 'Nuevo')
                ->has('activity', 14)
                ->where('activity.13.count', 1)
                ->where('activity.11.count', 1),
        );
    }

    /**
     * UI audit: "Eventos severos 0" right after a critical collision because
     * the card read the nightly risk snapshot. Counters are now live.
     */
    public function test_risk_counters_are_live_and_count_a_collision_right_away(): void
    {
        [$user, $team] = $this->createUserWithRole('live_risk', ['drivers.view']);
        $other = Team::factory()->create();

        $driver = Driver::factory()->create(['team_id' => $team->id]);
        DriverRiskProfile::factory()->create([
            'driver_id' => $driver->id,
            'risk_score' => 10,
            'last_calculated_at' => now()->subHours(10),
            'metadata_json' => ['window_days' => 30, 'severe_events_count' => 0],
        ]);

        $collision = EventType::factory()->create(['code' => 'collision']);

        NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'driver_id' => $driver->id,
            'event_type_id' => $collision->id,
            'occurred_at' => now()->subMinutes(2),
        ]);
        // Another tenant's collision carrying the same driver id never counts.
        NormalizedEvent::factory()->count(2)->create([
            'team_id' => $other->id,
            'driver_id' => $driver->id,
            'event_type_id' => $collision->id,
            'occurred_at' => now()->subMinutes(5),
        ]);

        $response = $this->assertNoTenantLeak(
            $team,
            fn () => $this->actingAs($user)->get(
                route('drivers.show', ['current_team' => $team->slug, 'driver' => $driver->id]),
            ),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('drivers/show')
                ->where('driver.riskProfile.severeEventsCount', 1)
                ->where('driver.riskProfile.riskScore', 10)
                ->has('driver.riskProfile.lastCalculatedAt'),
        );
    }

    public function test_last_signal_comes_from_real_activity_not_the_roster_sync(): void
    {
        [$user, $team] = $this->createUserWithRole('live_seen', ['drivers.view']);

        $driver = Driver::factory()->create([
            'team_id' => $team->id,
            'last_seen_at' => now()->subHours(10),
        ]);
        $asset = Asset::factory()->create(['team_id' => $team->id]);
        DriverAssignment::factory()->create([
            'team_id' => $team->id,
            'driver_id' => $driver->id,
            'asset_id' => $asset->id,
            'started_at' => now()->subDay(),
            'ended_at' => null,
        ]);
        NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'driver_id' => $driver->id,
            'occurred_at' => now()->subHours(2),
        ]);
        $location = AssetLocationSnapshot::factory()->create([
            'asset_id' => $asset->id,
            'recorded_at' => now()->subMinutes(56),
        ]);

        $response = $this->actingAs($user)->get(
            route('drivers.show', ['current_team' => $team->slug, 'driver' => $driver->id]),
        );

        $response->assertInertia(
            fn (Assert $page) => $page
                ->where('driver.lastSignalAt', $location->recorded_at->toIso8601String())
                ->where('driver.lastSeenAt', $driver->last_seen_at->toIso8601String()),
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
