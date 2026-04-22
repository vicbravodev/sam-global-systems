<?php

namespace App\Contracts;

interface DriverSyncHandler
{
    /**
     * Sync driver data discovered from an integration provider.
     *
     * @param  array<string, mixed>  $driverData
     */
    public function syncFromIntegration(int $teamId, int $integrationId, array $driverData): void;
}
