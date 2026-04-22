<?php

namespace App\Domains\Tenancy\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UsageLimitExceeded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $teamId,
        public string $meterCode,
        public int $consumed,
        public int $included,
    ) {}
}
