<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Events\DriverDiscovered;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverExternalReference;
use App\Domains\Integrations\Models\TenantIntegration;

class SyncDriverFromIntegration
{
    /**
     * @param  array<string, mixed>  $driverData
     */
    public function execute(int $teamId, int $integrationId, array $driverData): Driver
    {
        $integration = TenantIntegration::withoutGlobalScopes()->findOrFail($integrationId);
        $providerId = $integration->provider_id;
        $externalId = $driverData['external_id'];

        $existingDriver = $this->resolveByExternalReference($providerId, $externalId);

        if ($existingDriver) {
            return $this->updateExistingDriver($existingDriver, $driverData, $providerId);
        }

        return $this->createNewDriver($teamId, $providerId, $driverData);
    }

    private function resolveByExternalReference(int $providerId, string $externalId): ?Driver
    {
        $reference = DriverExternalReference::where('provider_id', $providerId)
            ->where('external_id', $externalId)
            ->first();

        if (! $reference) {
            return null;
        }

        return Driver::withoutGlobalScopes()->find($reference->driver_id);
    }

    /**
     * @param  array<string, mixed>  $driverData
     */
    private function updateExistingDriver(Driver $driver, array $driverData, int $providerId): Driver
    {
        $firstName = $driverData['first_name'] ?? $driver->first_name;
        $lastName = $driverData['last_name'] ?? $driver->last_name;

        $driver->update(array_filter([
            'first_name' => $driverData['first_name'] ?? null,
            'last_name' => $driverData['last_name'] ?? null,
            'full_name' => "{$firstName} {$lastName}",
            'employee_code' => $driverData['employee_code'] ?? null,
            'external_primary_id' => $driverData['external_id'],
            'metadata_json' => $driverData['metadata'] ?? null,
            'last_seen_at' => now(),
        ]));

        DriverExternalReference::where('provider_id', $providerId)
            ->where('external_id', $driverData['external_id'])
            ->update(['last_seen_at' => now()]);

        return $driver->fresh();
    }

    /**
     * @param  array<string, mixed>  $driverData
     */
    private function createNewDriver(int $teamId, int $providerId, array $driverData): Driver
    {
        $firstName = $driverData['first_name'];
        $lastName = $driverData['last_name'];

        $driver = Driver::withoutGlobalScopes()->create([
            'team_id' => $teamId,
            'external_primary_id' => $driverData['external_id'],
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => "{$firstName} {$lastName}",
            'employee_code' => $driverData['employee_code'] ?? null,
            'metadata_json' => $driverData['metadata'] ?? null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        DriverExternalReference::create([
            'driver_id' => $driver->id,
            'provider_id' => $providerId,
            'external_id' => $driverData['external_id'],
            'external_type' => $driverData['external_type'] ?? null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $providerCode = TenantIntegration::withoutGlobalScopes()
            ->where('provider_id', $providerId)
            ->first()
            ?->provider
            ?->code ?? 'unknown';

        DriverDiscovered::dispatch(
            $teamId,
            $driver->id,
            $driver->full_name,
            $providerCode,
            $driverData['external_id'],
        );

        return $driver;
    }
}
