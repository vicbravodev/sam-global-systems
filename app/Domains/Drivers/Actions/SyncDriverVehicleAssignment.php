<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Assets\Actions\ResolveAssetFromExternalId;
use App\Domains\Drivers\Enums\AssignmentSource;
use App\Domains\Drivers\Enums\AssignmentType;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverAssignment;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lleva a `driver_assignments` la asignación estática conductor↔vehículo que
 * el proveedor reporta en el sync de conductores (Samsara:
 * `staticAssignedVehicle`). Sólo escribe cuando cambia: el mismo vehículo de
 * siempre no abre otra fila.
 *
 * - Vehículo nuevo: cierra la asignación de integración abierta del conductor
 *   (si la hay) y abre una primaria con `AssignDriverToAsset`, que a su vez
 *   cierra la primaria vigente del vehículo.
 * - Sin vehículo: cierra la asignación de integración abierta.
 * - El id externo del vehículo se resuelve SIEMPRE dentro del team del
 *   conductor; uno de otro tenant no resuelve y se omite.
 * - Un fallo aquí nunca rompe el sync del conductor: se registra y sigue.
 */
class SyncDriverVehicleAssignment
{
    public function __construct(
        private readonly ResolveAssetFromExternalId $resolveAsset,
        private readonly AssignDriverToAsset $assignDriverToAsset,
    ) {}

    public function execute(Driver $driver, int $integrationId, ?string $vehicleExternalId): void
    {
        $input = [
            'team_id' => $driver->team_id,
            'driver_id' => $driver->id,
            'integration_id' => $integrationId,
            'vehicle_external_id' => $vehicleExternalId,
        ];

        try {
            TenantContext::for($driver->team_id, fn () => DB::transaction(
                fn () => $this->sync($driver, $integrationId, $vehicleExternalId, $input),
            ));
        } catch (Throwable $e) {
            SystemLog::degraded('drivers.assignment.sync_failed', reason: 'write_failed', input: $input, error: $e);
        }
    }

    /**
     * @param  array<string, int|string|null>  $input
     */
    private function sync(Driver $driver, int $integrationId, ?string $vehicleExternalId, array $input): void
    {
        $open = DriverAssignment::query()
            ->where('team_id', $driver->team_id)
            ->where('driver_id', $driver->id)
            ->where('assignment_type', AssignmentType::PrimaryDriver)
            ->where('source', AssignmentSource::Integration)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();

        if ($vehicleExternalId === null || $vehicleExternalId === '') {
            if ($open === null) {
                SystemLog::skipped('drivers.assignment.synced', reason: 'unassigned', input: $input, debug: true);

                return;
            }

            $open->update(['ended_at' => now()]);
            SystemLog::ok('drivers.assignment.synced', input: $input, result: ['outcome' => 'ended', 'assignment_id' => $open->id, 'asset_id' => $open->asset_id]);

            return;
        }

        $providerId = TenantIntegration::query()
            ->where('team_id', $driver->team_id)
            ->whereKey($integrationId)
            ->value('provider_id');

        $asset = is_int($providerId)
            ? $this->resolveAsset->execute($providerId, $vehicleExternalId, $driver->team_id)
            : null;

        if ($asset === null) {
            // Vehículo aún no sincronizado, o id externo de otro tenant.
            SystemLog::skipped('drivers.assignment.synced', reason: 'asset_not_found', input: $input);

            return;
        }

        if ($open !== null && $open->asset_id === $asset->id) {
            SystemLog::skipped('drivers.assignment.synced', reason: 'unchanged', input: $input, calc: ['asset_id' => $asset->id], debug: true);

            return;
        }

        $open?->update(['ended_at' => now()]);

        $assignment = $this->assignDriverToAsset->execute(
            teamId: $driver->team_id,
            driverId: $driver->id,
            assetId: $asset->id,
            assignmentType: AssignmentType::PrimaryDriver,
            source: AssignmentSource::Integration,
            sourceReferenceId: $vehicleExternalId,
        );

        SystemLog::ok('drivers.assignment.synced', input: $input, result: [
            'outcome' => 'assigned',
            'assignment_id' => $assignment->id,
            'asset_id' => $asset->id,
            'previous_asset_id' => $open?->asset_id,
        ]);
    }
}
