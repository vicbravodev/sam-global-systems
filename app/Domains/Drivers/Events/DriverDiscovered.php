<?php

namespace App\Domains\Drivers\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DriverDiscovered
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $driverId,
        public readonly string $fullName,
        public readonly string $providerCode,
        public readonly string $externalId,
    ) {}
}
