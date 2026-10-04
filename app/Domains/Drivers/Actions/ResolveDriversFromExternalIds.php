<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverExternalReference;

/**
 * Batch provider-id → Driver resolution for fleet polls, tenant-scoped: the
 * provider's driver ids are unique platform-wide, so `$teamId` is applied
 * explicitly and another tenant's driver with the same external id is absent.
 */
class ResolveDriversFromExternalIds
{
    private const int CHUNK_SIZE = 1000;

    /**
     * @param  iterable<int, string>  $externalIds
     * @return array<string, Driver> keyed by external id
     */
    public function execute(int $providerId, iterable $externalIds, int $teamId): array
    {
        $ids = [];

        foreach ($externalIds as $externalId) {
            if ($externalId !== '') {
                $ids[$externalId] = true;
            }
        }

        $resolved = [];

        foreach (array_chunk(array_map('strval', array_keys($ids)), self::CHUNK_SIZE) as $chunk) {
            $driverIdsByExternalId = DriverExternalReference::query()
                ->where('provider_id', $providerId)
                ->whereIn('external_id', $chunk)
                ->pluck('driver_id', 'external_id');

            if ($driverIdsByExternalId->isEmpty()) {
                continue;
            }

            $drivers = Driver::query()
                ->where('team_id', $teamId)
                ->whereKey($driverIdsByExternalId->values()->unique()->all())
                ->get()
                ->keyBy('id');

            foreach ($driverIdsByExternalId as $externalId => $driverId) {
                $driver = $drivers->get($driverId);

                if ($driver !== null) {
                    $resolved[(string) $externalId] = $driver;
                }
            }
        }

        return $resolved;
    }
}
