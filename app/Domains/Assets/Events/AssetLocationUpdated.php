<?php

namespace App\Domains\Assets\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssetLocationUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $assetId,
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly string $recordedAt,
    ) {}
}
