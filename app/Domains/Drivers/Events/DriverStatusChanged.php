<?php

namespace App\Domains\Drivers\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DriverStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $driverId,
        public readonly string $previousStatus,
        public readonly string $newStatus,
    ) {}
}
