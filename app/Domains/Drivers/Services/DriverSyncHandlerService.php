<?php

namespace App\Domains\Drivers\Services;

use App\Contracts\DriverSyncHandler;
use App\Domains\Drivers\Actions\SyncDriverFromIntegration;

class DriverSyncHandlerService implements DriverSyncHandler
{
    public function __construct(
        private SyncDriverFromIntegration $syncDriverAction,
    ) {}

    public function syncFromIntegration(int $teamId, int $integrationId, array $driverData): void
    {
        $this->syncDriverAction->execute($teamId, $integrationId, $driverData);
    }
}
