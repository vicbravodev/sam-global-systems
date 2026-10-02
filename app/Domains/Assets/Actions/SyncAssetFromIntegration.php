<?php

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Enums\AssetMonitoringState;
use App\Domains\Assets\Events\AssetDiscovered;
use App\Domains\Assets\Exceptions\AssetExternalReferenceConflictException;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

class SyncAssetFromIntegration
{
    public function __construct(
        private ResolveAssetFromExternalId $resolveAsset,
        private SyncAssetDevices $syncDevices,
    ) {}

    /**
     * @param  array<string, mixed>  $assetData
     */
    public function execute(int $teamId, int $integrationId, array $assetData): Asset
    {
        // Lookup de entrada: el sync llega con el id de la integración y de
        // ahí sale el tenant, así que aquí todavía no puede haber scope.
        $integration = TenantIntegration::withoutGlobalScopes()->findOrFail($integrationId);

        // El resto trabaja dentro de ese tenant. Es una Action, no un job, así
        // que se usa for() y no set(): el contexto del que llama se restaura
        // al salir. Ver §2.1.
        return TenantContext::for($integration->team_id, function () use ($integration, $teamId, $integrationId, $assetData) {
            $providerId = $integration->provider_id;
            $externalId = $assetData['external_id'];

            $existingAsset = $this->resolveAsset->execute($providerId, $externalId, $teamId);

            if ($existingAsset !== null) {
                $asset = $this->updateExistingAsset($existingAsset, $assetData, $providerId);
            } else {
                $this->assertExternalIdIsUnclaimed($teamId, $providerId, $externalId);
                $asset = $this->createNewAsset($teamId, $integrationId, $providerId, $assetData);
            }

            $this->reconcileDevices($asset, $providerId, $assetData);

            // Una línea por activo (en debug): nunca nombre, clave, metadata
            // ni external_type del activo.
            SystemLog::ok('assets.sync.asset_applied', input: [
                'team_id' => $teamId,
                'asset_id' => $asset->id,
                'integration_id' => $integrationId,
            ], calc: [
                'branch' => $existingAsset !== null ? 'updated' : 'created',
                'devices_reported' => array_key_exists('devices', $assetData) && is_array($assetData['devices']),
            ], debug: true);

            return $asset;
        });
    }

    /**
     * Register the gateway/camera the provider reports for this asset. Only when
     * `devices` is actually present: a provider that does not report devices at
     * all must never be read as "this asset has no devices", which would detach
     * the whole fleet on the next sync.
     *
     * @param  array<string, mixed>  $assetData
     */
    private function reconcileDevices(Asset $asset, int $providerId, array $assetData): void
    {
        if (! array_key_exists('devices', $assetData) || ! is_array($assetData['devices'])) {
            return;
        }

        $this->syncDevices->execute($asset, $providerId, $assetData['devices']);
    }

    /**
     * The resolver only hands back assets of `$teamId`, so reaching this point
     * with a reference already on file means another tenant owns the external
     * id. Refuse loudly instead of writing over their asset (the old behaviour)
     * or crashing on the unique index a moment later.
     */
    private function assertExternalIdIsUnclaimed(int $teamId, int $providerId, string $externalId): void
    {
        $claimed = AssetExternalReference::where('provider_id', $providerId)
            ->where('external_id', $externalId)
            ->exists();

        if ($claimed) {
            throw new AssetExternalReferenceConflictException($teamId, $providerId, $externalId);
        }
    }

    /**
     * @param  array<string, mixed>  $assetData
     */
    private function updateExistingAsset(Asset $asset, array $assetData, int $providerId): Asset
    {
        // Deliberately does NOT bump the asset's `last_seen_at`: this is an
        // inventory sync (the provider still lists the asset), not a real
        // signal. `last_seen_at` only moves with actual telemetry/location
        // (UpdateAssetLocationSnapshot), otherwise the whole fleet looks
        // "seen minutes ago" forever (C1-a) and offline detection goes blind.
        // Un campo ausente, vacío o `[]` no pisa lo guardado; un nombre o
        // código "0" sí es un valor real (la creación también lo guarda).
        $asset->update(array_filter([
            'name' => $assetData['name'] ?? null,
            'code' => $assetData['code'] ?? null,
            'external_primary_id' => $assetData['external_id'],
            'metadata_json' => $assetData['metadata'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []));

        AssetExternalReference::where('provider_id', $providerId)
            ->where('external_id', $assetData['external_id'])
            ->update(['last_seen_at' => now()]);

        // refresh() y no fresh(): misma relectura, pero si la fila
        // desapareciera lanza ModelNotFound en vez de devolver null.
        return $asset->refresh();
    }

    /**
     * @param  array<string, mixed>  $assetData
     */
    private function createNewAsset(int $teamId, int $integrationId, int $providerId, array $assetData): Asset
    {
        $assetType = $this->resolveAssetType($assetData['asset_type_code'] ?? 'vehicle');

        // El activo y su referencia externa nacen juntos o no nacen: si la
        // referencia (única por provider+external_id) fallara tras crear el
        // activo, quedaría un `pending` huérfano que el siguiente sync, sin
        // referencia que lo resuelva, duplicaría.
        $asset = DB::transaction(function () use ($teamId, $integrationId, $providerId, $assetData, $assetType): Asset {
            $asset = Asset::query()->create([
                'team_id' => $teamId,
                'asset_type_id' => $assetType->id,
                'provider_id' => $providerId,
                'source_integration_id' => $integrationId,
                'external_primary_id' => $assetData['external_id'],
                'name' => $assetData['name'] ?? 'Unknown Asset',
                'code' => $assetData['code'] ?? null,
                'metadata_json' => $assetData['metadata'] ?? null,
                // El sync descubre TODA la flota del proveedor sin tope: la unidad
                // entra al inventario como `pending` y el cliente decide si la
                // enciende (SetAssetMonitoring). Nada se vigila ni se cobra solo.
                'monitoring_state' => AssetMonitoringState::Pending,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);

            AssetExternalReference::create([
                'asset_id' => $asset->id,
                'provider_id' => $providerId,
                'external_id' => $assetData['external_id'],
                'external_type' => $assetData['external_type'] ?? null,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);

            return $asset;
        });

        AssetDiscovered::dispatch(
            $teamId,
            $asset->id,
            $assetType->code,
            // provider_id viene de la integración (FK con constraint, el
            // proveedor no se soft-deletea): findOrFail sólo expresa ese contrato.
            IntegrationProvider::query()->findOrFail($providerId)->code,
            $assetData['external_id'],
        );

        return $asset;
    }

    private function resolveAssetType(string $code): AssetType
    {
        return AssetType::where('code', $code)->firstOrFail();
    }
}
