<?php

namespace App\Domains\Drivers\Services;

use App\Contracts\DriverSyncHandler;
use App\Domains\Drivers\Actions\SyncDriverFromIntegration;
use App\Domains\Drivers\Actions\SyncDriverVehicleAssignment;
use App\Domains\Drivers\Exceptions\DriverExternalReferenceConflictException;

class DriverSyncHandlerService implements DriverSyncHandler
{
    public function __construct(
        private SyncDriverFromIntegration $syncDriverAction,
        private SyncDriverVehicleAssignment $syncVehicleAssignment,
    ) {}

    public function syncFromIntegration(int $teamId, int $integrationId, array $driverData): void
    {
        try {
            $driver = $this->syncDriverAction->execute($teamId, $integrationId, $driverData);
        } catch (DriverExternalReferenceConflictException $e) {
            // The external id already belongs to another tenant's driver:
            // skip it (and log it) instead of aborting the whole batch sync.
            $e->logSkipped();

            return;
        }

        // Only providers that report a static driver↔vehicle assignment send
        // the key (null = no vehicle assigned); without it nothing is touched.
        if (array_key_exists('static_vehicle_external_id', $driverData)) {
            $vehicleExternalId = $driverData['static_vehicle_external_id'];

            $this->syncVehicleAssignment->execute(
                $driver,
                $integrationId,
                is_scalar($vehicleExternalId) ? (string) $vehicleExternalId : null,
            );
        }
    }
}
