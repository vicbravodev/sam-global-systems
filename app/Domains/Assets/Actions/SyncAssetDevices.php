<?php

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Enums\DeviceStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetDevice;

/**
 * Reconcile an asset's devices against the list the provider currently reports.
 *
 * Devices are inventory, not events: the provider tells us the full set on every
 * catalog sync, so this action is a full reconciliation — listed devices are
 * attached or refreshed, previously-attached ones that disappeared are closed
 * with `detached_at`. Callers must only invoke it when the provider actually
 * reported a device list; an empty list means "this asset has no devices right
 * now", which is not the same as "this provider does not report devices".
 */
class SyncAssetDevices
{
    public function __construct(
        private AttachDeviceToAsset $attachDevice,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $devices  Each entry: device_type, external_device_id, metadata.
     * @return array<int, AssetDevice>
     */
    public function execute(Asset $asset, ?int $providerId, array $devices): array
    {
        $attached = [];
        $seen = [];

        foreach ($devices as $device) {
            $externalDeviceId = $device['external_device_id'] ?? null;

            // Without an external id the device cannot be matched on the next
            // sync, so registering it would create a duplicate every tick.
            if (! is_string($externalDeviceId) || $externalDeviceId === '') {
                continue;
            }

            $attached[] = $this->attachDevice->execute(
                asset: $asset,
                deviceType: (string) ($device['device_type'] ?? 'unknown'),
                providerId: $providerId,
                externalDeviceId: $externalDeviceId,
                metadata: isset($device['metadata']) && is_array($device['metadata'])
                    ? $device['metadata']
                    : null,
            );

            $seen[] = $externalDeviceId;
        }

        $this->detachMissing($asset, $providerId, $seen);

        return $attached;
    }

    /**
     * @param  array<int, string>  $seen
     */
    private function detachMissing(Asset $asset, ?int $providerId, array $seen): void
    {
        $query = AssetDevice::where('asset_id', $asset->id)
            ->where('status', '!=', DeviceStatus::Detached)
            ->whereNull('detached_at');

        // Only reconcile what this provider owns; devices registered by another
        // provider (or by hand) are none of this sync's business.
        if ($providerId) {
            $query->where('provider_id', $providerId);
        }

        if ($seen !== []) {
            $query->whereNotIn('external_device_id', $seen);
        }

        $query->update([
            'status' => DeviceStatus::Detached,
            'detached_at' => now(),
        ]);
    }
}
