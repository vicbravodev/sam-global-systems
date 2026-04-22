<?php

namespace App\Domains\Assets\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssetDiscovered
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $assetId,
        public readonly string $assetTypeCode,
        public readonly string $providerCode,
        public readonly string $externalId,
    ) {}
}
