<?php

namespace App\Domains\Drivers\Services;

use App\Contracts\DriverSyncHandler;
use App\Domains\Drivers\Actions\SyncDriverFromIntegration;
use App\Domains\Drivers\Exceptions\DriverExternalReferenceConflictException;

class DriverSyncHandlerService implements DriverSyncHandler
{
    public function __construct(
        private SyncDriverFromIntegration $syncDriverAction,
    ) {}

    public function syncFromIntegration(int $teamId, int $integrationId, array $driverData): void
    {
        try {
            $this->syncDriverAction->execute($teamId, $integrationId, $driverData);
        } catch (DriverExternalReferenceConflictException $e) {
            // The external id already belongs to another tenant's driver:
            // skip it (and log it) instead of aborting the whole batch sync.
            $e->logSkipped();
        }
    }
}
