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
        [$incomingFirst, $incomingLast] = $this->resolveName($driverData);

        $firstName = $incomingFirst ?? $driver->first_name;
        $lastName = $incomingLast ?? $driver->last_name;
        $fullName = trim("{$firstName} {$lastName}");

        $driver->update(array_filter([
            'first_name' => $incomingFirst,
            'last_name' => $incomingLast,
            'full_name' => $fullName !== '' ? $fullName : $driver->full_name,
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
        [$first, $last] = $this->resolveName($driverData);

        $firstName = (string) ($first ?? '');
        $lastName = (string) ($last ?? '');
        $fullName = trim("{$firstName} {$lastName}");

        if ($fullName === '') {
            $fullName = (string) ($driverData['name'] ?? 'Unknown Driver');
        }

        $driver = Driver::withoutGlobalScopes()->create([
            'team_id' => $teamId,
            'external_primary_id' => $driverData['external_id'],
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $fullName,
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

    /**
     * Resolve the driver's first/last name from the integration payload.
     *
     * Providers vary: some send structured `first_name`/`last_name`, others
     * (e.g. Samsara) only a single `name`. Falls back to splitting `name` and
     * returns nulls when nothing usable is present, so callers can decide
     * between defaults (create) and keeping existing values (update).
     *
     * @param  array<string, mixed>  $driverData
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveName(array $driverData): array
    {
        $first = $driverData['first_name'] ?? null;
        $last = $driverData['last_name'] ?? null;

        $hasFirst = $first !== null && $first !== '';
        $hasLast = $last !== null && $last !== '';

        if (! $hasFirst && ! $hasLast && ! empty($driverData['name'])) {
            $parts = preg_split('/\s+/', trim((string) $driverData['name']), 2);
            $first = $parts[0] ?? null;
            $last = $parts[1] ?? null;
        }

        return [$first, $last];
    }
}
