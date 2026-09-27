<?php

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Enums\DeviceStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetDevice;

class AttachDeviceToAsset
{
    /**
     * Attach a device to an asset, idempotently on `external_device_id`.
     *
     * The provider sync replays the same device list on every tick, so an
     * already-attached device is refreshed in place instead of piling up a new
     * row per sync. A device that shows up on a *different* asset is treated as
     * a physical re-installation: the previous attachment is closed first.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function execute(
        Asset $asset,
        string $deviceType,
        ?int $providerId = null,
        ?string $externalDeviceId = null,
        ?array $metadata = null,
    ): AssetDevice {
        if ($externalDeviceId !== null && $externalDeviceId !== '') {
            $existing = $this->findAttachedToAsset($asset, $externalDeviceId, $providerId);

            if ($existing !== null) {
                $existing->update([
                    'device_type' => $deviceType,
                    'metadata_json' => $metadata,
                ]);

                return $existing;
            }

            $this->detachFromPreviousAsset($asset, $externalDeviceId, $providerId);
        }

        return AssetDevice::create([
            'asset_id' => $asset->id,
            'device_type' => $deviceType,
            'provider_id' => $providerId,
            'external_device_id' => $externalDeviceId,
            'status' => DeviceStatus::Active,
            'attached_at' => now(),
            'metadata_json' => $metadata,
        ]);
    }

    private function findAttachedToAsset(Asset $asset, string $externalDeviceId, ?int $providerId): ?AssetDevice
    {
        $query = AssetDevice::where('asset_id', $asset->id)
            ->where('external_device_id', $externalDeviceId)
            ->where('status', '!=', DeviceStatus::Detached)
            ->whereNull('detached_at');

        if ($providerId) {
            $query->where('provider_id', $providerId);
        }

        return $query->first();
    }

    /**
     * Close any open attachment of the same device on another asset.
     *
     * Scoped to the asset's own tenant: `asset_devices` carries no `team_id`, so
     * without the scope a serial collision across tenants would let one tenant's
     * sync detach another tenant's device.
     */
    private function detachFromPreviousAsset(Asset $asset, string $externalDeviceId, ?int $providerId): void
    {
        $query = AssetDevice::where('external_device_id', $externalDeviceId)
            ->where('status', '!=', DeviceStatus::Detached)
            ->whereNull('detached_at')
            ->whereIn('asset_id', Asset::query()
                ->where('team_id', $asset->team_id)
                ->select('id'));

        if ($providerId) {
            $query->where('provider_id', $providerId);
        }

        $query->update([
            'status' => DeviceStatus::Detached,
            'detached_at' => now(),
        ]);
    }
}
