<?php

namespace App\Domains\Analytics\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class KPIsCalculated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly int $metricsCount,
    ) {}
}
