<?php

namespace App\Domains\Drivers\Data;

use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Integrations\Data\HosClockReading;

final readonly class HosEnrollment
{
    /**
     * @param  array<int, array{reading: HosClockReading, driver: Driver, asset: Asset}>  $enrolled
     * @param  array<string, int>  $skippedByReason
     */
    public function __construct(
        public array $enrolled,
        public array $skippedByReason,
    ) {}
}
