<?php

namespace App\Contracts\NullImplementations;

use App\Contracts\DriverSyncHandler;

class NullDriverSyncHandler implements DriverSyncHandler
{
    public function syncFromIntegration(int $teamId, int $integrationId, array $driverData): void
    {
        // No-op: will be replaced when the Drivers domain is implemented.
    }
}
