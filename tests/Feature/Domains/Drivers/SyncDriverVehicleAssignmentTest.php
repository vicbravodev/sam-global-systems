<?php

namespace Tests\Feature\Domains\Drivers;

use App\Contracts\DriverSyncHandler;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Drivers\Actions\AssignDriverToAsset;
use App\Domains\Drivers\Enums\AssignmentSource;
use App\Domains\Drivers\Enums\AssignmentType;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverAssignment;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * La asignación estática conductor↔vehículo de Samsara
 * (`staticAssignedVehicle`) llega con el sync de conductores y alimenta
 * `driver_assignments`: sólo se escribe cuando cambia.
 */
class SyncDriverVehicleAssignmentTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private Team $team;

    private IntegrationProvider $provider;

    private TenantIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();
        $this->provider = IntegrationProvider::factory()->samsara()->create();
        $this->integration = TenantIntegration::factory()->active()->create([
            'team_id' => $this->team->id,
            'provider_id' => $this->provider->id,
        ]);
    }

    private function vehicle(string $externalId, ?Team $team = null): Asset
    {
        $asset = Asset::factory()->create(['team_id' => ($team ?? $this->team)->id]);
        AssetExternalReference::factory()->create([
            'asset_id' => $asset->id,
            'provider_id' => $this->provider->id,
            'external_id' => $externalId,
        ]);

        return $asset;
    }

    private function sync(?string $vehicleExternalId, ?TenantIntegration $integration = null): void
    {
        $integration ??= $this->integration;

        app(DriverSyncHandler::class)->syncFromIntegration($integration->team_id, $integration->id, [
            'external_id' => 'drv-1',
            'name' => 'Juan Pérez',
            'static_vehicle_external_id' => $vehicleExternalId,
        ]);
    }

    private function driver(): Driver
    {
        return Driver::withoutGlobalScopes()->where('team_id', $this->team->id)->sole();
    }

    public function test_static_vehicle_opens_a_primary_integration_assignment(): void
    {
        $truck = $this->vehicle('100');

        $this->sync('100');

        $assignment = DriverAssignment::withoutGlobalScopes()->sole();
        $this->assertSame($this->team->id, $assignment->team_id);
        $this->assertSame($this->driver()->id, $assignment->driver_id);
        $this->assertSame($truck->id, $assignment->asset_id);
        $this->assertSame(AssignmentType::PrimaryDriver, $assignment->assignment_type);
        $this->assertSame(AssignmentSource::Integration, $assignment->source);
        $this->assertSame('100', $assignment->source_reference_id);
        $this->assertNull($assignment->ended_at);
        $this->assertSystemLogged('drivers.assignment.synced', fn (array $context): bool => $context['result']['outcome'] === 'assigned'
            && $context['result']['asset_id'] === $truck->id);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_unchanged_assignment_writes_nothing_on_the_next_sync(): void
    {
        $this->vehicle('100');

        $this->sync('100');
        $this->sync('100');

        $this->assertSame(1, DriverAssignment::withoutGlobalScopes()->count());
        $this->assertSystemLogged('drivers.assignment.synced', fn (array $context): bool => ($context['reason'] ?? null) === 'unchanged');
    }

    public function test_vehicle_change_closes_the_previous_assignment_and_opens_a_new_one(): void
    {
        $first = $this->vehicle('100');
        $second = $this->vehicle('200');

        $this->sync('100');
        $this->sync('200');

        $rows = DriverAssignment::withoutGlobalScopes()->orderBy('id')->get()->all();
        $this->assertCount(2, $rows);
        [$old, $new] = $rows;
        $this->assertSame($first->id, $old->asset_id);
        $this->assertNotNull($old->ended_at);
        $this->assertSame($second->id, $new->asset_id);
        $this->assertNull($new->ended_at);
    }

    public function test_driver_without_static_vehicle_closes_the_open_integration_assignment(): void
    {
        $this->vehicle('100');
        $this->sync('100');

        $this->sync(null);

        $this->assertNotNull(DriverAssignment::withoutGlobalScopes()->sole()->ended_at);
        $this->assertSystemLogged('drivers.assignment.synced', fn (array $context): bool => $context['result']['outcome'] === 'ended');
    }

    public function test_provider_without_assignment_data_leaves_assignments_untouched(): void
    {
        $this->vehicle('100');
        $this->sync('100');

        // Un proveedor que no reporta asignaciones no manda la clave: no se
        // cierra nada.
        app(DriverSyncHandler::class)->syncFromIntegration($this->team->id, $this->integration->id, [
            'external_id' => 'drv-1',
            'name' => 'Juan Pérez',
        ]);

        $this->assertNull(DriverAssignment::withoutGlobalScopes()->sole()->ended_at);
    }

    public function test_unknown_vehicle_is_skipped(): void
    {
        $this->sync('999');

        $this->assertSame(0, DriverAssignment::withoutGlobalScopes()->count());
        $this->assertSystemLogged('drivers.assignment.synced', fn (array $context): bool => ($context['reason'] ?? null) === 'asset_not_found');
    }

    public function test_failing_assignment_never_breaks_the_driver_sync(): void
    {
        $this->vehicle('100');
        $this->mock(AssignDriverToAsset::class, fn (MockInterface $mock) => $mock->shouldReceive('execute')
            ->andThrow(new RuntimeException('driver_assignments no disponible')));

        $this->sync('100');

        $this->assertSame('Juan Pérez', $this->driver()->full_name);
        $this->assertSame(0, DriverAssignment::withoutGlobalScopes()->count());
        $this->assertSystemLogged('drivers.assignment.sync_failed', fn (array $context): bool => $context['reason'] === 'write_failed'
            && $context['input']['driver_id'] === $this->driver()->id);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_vehicle_external_id_owned_by_another_tenant_never_assigns(): void
    {
        $otherTeam = Team::factory()->create();
        $foreignTruck = $this->vehicle('100', $otherTeam);

        $this->assertNoTenantLeak($this->team, fn () => $this->sync('100'));

        $this->assertSame(0, DriverAssignment::withoutGlobalScopes()->count());
        $this->assertFalse(DriverAssignment::withoutGlobalScopes()->where('asset_id', $foreignTruck->id)->exists());
        $this->assertSystemLogged('drivers.assignment.synced', fn (array $context): bool => ($context['reason'] ?? null) === 'asset_not_found');
    }

    public function test_sync_never_closes_another_tenant_assignment(): void
    {
        $otherTeam = Team::factory()->create();
        $foreignAsset = Asset::factory()->create(['team_id' => $otherTeam->id]);
        $foreignDriver = Driver::factory()->create(['team_id' => $otherTeam->id]);
        $foreign = DriverAssignment::factory()->create([
            'team_id' => $otherTeam->id,
            'driver_id' => $foreignDriver->id,
            'asset_id' => $foreignAsset->id,
            'assignment_type' => AssignmentType::PrimaryDriver,
            'source' => AssignmentSource::Integration,
            'ended_at' => null,
        ]);
        $this->vehicle('100');

        $this->assertNoTenantLeak($this->team, function (): void {
            $this->sync('100');
            $this->sync(null);
        });

        $this->assertNull($foreign->fresh()?->ended_at);
    }
}
