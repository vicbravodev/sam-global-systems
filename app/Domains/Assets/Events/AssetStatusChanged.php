<?php

namespace App\Domains\Assets\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssetStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $assetId,
        public readonly string $previousStatus,
        public readonly string $newStatus,
    ) {}
}
