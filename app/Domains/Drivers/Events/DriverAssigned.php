<?php

namespace App\Domains\Drivers\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DriverAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $driverId,
        public readonly int $assetId,
        public readonly string $assignmentType,
        public readonly \DateTimeInterface $startedAt,
    ) {}
}
