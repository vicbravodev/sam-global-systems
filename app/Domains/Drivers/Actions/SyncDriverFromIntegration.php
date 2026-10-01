<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Events\DriverDiscovered;
use App\Domains\Drivers\Exceptions\DriverExternalReferenceConflictException;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverExternalReference;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Support\PhoneNumber;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class SyncDriverFromIntegration
{
    /**
     * @param  array<string, mixed>  $driverData
     */
    public function execute(int $teamId, int $integrationId, array $driverData): Driver
    {
        // Lookup de entrada: el sync llega con el id de la integración y de
        // ahí sale el tenant, así que aquí todavía no puede haber scope.
        $integration = TenantIntegration::withoutGlobalScopes()->findOrFail($integrationId);

        // El resto trabaja dentro de ese tenant. Es una Action, no un job, así
        // que se usa for() y no set(): el contexto del que llama se restaura
        // al salir. Ver §2.1.
        return TenantContext::for($integration->team_id, function () use ($integration, $teamId, $driverData) {
            $providerId = $integration->provider_id;
            $externalId = (string) $driverData['external_id'];

            $existingDriver = $this->resolveByExternalReference($providerId, $externalId, $teamId);

            if ($existingDriver !== null) {
                return $this->updateExistingDriver($existingDriver, $driverData, $providerId);
            }

            // Antes de crear NADA: si la referencia existe y no resolvió a un
            // driver de este team, es de otro tenant. Crear el driver primero
            // dejaba un huérfano en este team al chocar el unique de la
            // referencia, y abortaba el resto del lote.
            $this->assertExternalIdIsUnclaimed($teamId, $providerId, $externalId);

            return DB::transaction(fn () => $this->createNewDriver($teamId, $providerId, $driverData));
        });
    }

    /**
     * `driver_external_references` es único por (provider_id, external_id) en
     * toda la plataforma: un id externo apunta a UN driver de cualquier tenant.
     * La resolución filtra explícitamente por `$teamId` (sin depender del
     * scope ambiente), así que una referencia de otro tenant resuelve a null.
     */
    private function resolveByExternalReference(int $providerId, string $externalId, int $teamId): ?Driver
    {
        $reference = DriverExternalReference::where('provider_id', $providerId)
            ->where('external_id', $externalId)
            ->first();

        if ($reference === null) {
            return null;
        }

        return Driver::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($reference->driver_id);
    }

    /**
     * El resolver sólo devuelve drivers de `$teamId`: si aun así hay una
     * referencia registrada, el id externo es de otro tenant. Se rechaza en
     * voz alta en vez de pisar su driver o chocar contra el índice único.
     */
    private function assertExternalIdIsUnclaimed(int $teamId, int $providerId, string $externalId): void
    {
        $claimed = DriverExternalReference::where('provider_id', $providerId)
            ->where('external_id', $externalId)
            ->exists();

        if ($claimed) {
            throw new DriverExternalReferenceConflictException($teamId, $providerId, $externalId);
        }
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

        // Un campo ausente o vacío conserva el valor guardado; "0" es un valor
        // real (código de empleado, apellido) y se aplica igual que al crear.
        $driver->update(array_filter([
            'first_name' => $incomingFirst,
            'last_name' => $incomingLast,
            'full_name' => $fullName !== '' ? $fullName : $driver->full_name,
            'employee_code' => $driverData['employee_code'] ?? null,
            'phone' => PhoneNumber::normalize($driverData['phone'] ?? null),
            'external_primary_id' => $driverData['external_id'],
            'metadata_json' => $driverData['metadata'] ?? null,
            'last_seen_at' => now(),
        ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []));

        DriverExternalReference::where('provider_id', $providerId)
            ->where('external_id', $driverData['external_id'])
            ->update(['last_seen_at' => now()]);

        return $driver->fresh()
            ?? throw (new ModelNotFoundException)->setModel(Driver::class, [$driver->id]);
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

        $driver = Driver::query()->create([
            'team_id' => $teamId,
            'external_primary_id' => $driverData['external_id'],
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $fullName,
            'employee_code' => $driverData['employee_code'] ?? null,
            'phone' => PhoneNumber::normalize($driverData['phone'] ?? null),
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

        $providerCode = TenantIntegration::query()
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
     *                                            Los valores estructurados llegan tal cual del payload (no siempre son
     *                                            string), por eso el tipo es mixed y quien llama los castea.
     * @return array{0: mixed, 1: mixed}
     */
    private function resolveName(array $driverData): array
    {
        $first = $driverData['first_name'] ?? null;
        $last = $driverData['last_name'] ?? null;

        $hasFirst = $first !== null && $first !== '';
        $hasLast = $last !== null && $last !== '';

        // Equivalente exacto del `! empty()` previo (un nombre "0" cuenta como
        // ausente, igual que antes).
        $hasName = ! in_array($driverData['name'] ?? null, [null, false, 0, 0.0, '', '0', []], true);

        if (! $hasFirst && ! $hasLast && $hasName) {
            $parts = preg_split('/\s+/', trim((string) $driverData['name']), 2);
            $first = $parts[0] ?? null;
            $last = $parts[1] ?? null;
        }

        return [$first, $last];
    }
}
