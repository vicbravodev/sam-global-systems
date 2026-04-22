<?php

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Events\AssetStatusChanged;
use App\Domains\Assets\Events\AssetStatusChangedBroadcast;
use App\Domains\Assets\Models\Asset;

class UpdateAssetStatus
{
    public function execute(Asset $asset, AssetStatus $newStatus): void
    {
        $previousStatus = $asset->status;

        if ($previousStatus === $newStatus) {
            return;
        }

        $asset->update(['status' => $newStatus]);

        AssetStatusChanged::dispatch(
            $asset->team_id,
            $asset->id,
            $previousStatus->value,
            $newStatus->value,
        );

        broadcast(new AssetStatusChangedBroadcast(
            $asset->team_id,
            $asset->id,
            $asset->name,
            $previousStatus->value,
            $newStatus->value,
        ));
    }
}
