<?php

namespace App\Domains\Assets\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssetMonitoringChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $assetId,
        public readonly string $previousState,
        public readonly string $newState,
        public readonly ?int $actorId,
        public readonly bool $overCap,
    ) {}
}
