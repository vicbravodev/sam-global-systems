<?php

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Enums\DeviceStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetDevice;

class AttachDeviceToAsset
{
    public function execute(
        Asset $asset,
        string $deviceType,
        ?int $providerId = null,
        ?string $externalDeviceId = null,
        ?array $metadata = null,
    ): AssetDevice {
        if ($externalDeviceId) {
            $this->detachFromPreviousAsset($externalDeviceId, $providerId);
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

    private function detachFromPreviousAsset(string $externalDeviceId, ?int $providerId): void
    {
        $query = AssetDevice::where('external_device_id', $externalDeviceId)
            ->where('status', '!=', DeviceStatus::Detached)
            ->whereNull('detached_at');

        if ($providerId) {
            $query->where('provider_id', $providerId);
        }

        $query->update([
            'status' => DeviceStatus::Detached,
            'detached_at' => now(),
        ]);
    }
}
