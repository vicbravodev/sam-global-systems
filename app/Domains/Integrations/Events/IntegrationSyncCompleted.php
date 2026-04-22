<?php

namespace App\Domains\Integrations\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IntegrationSyncCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $integrationId,
        public readonly int $syncJobId,
        public readonly int $recordsProcessed,
    ) {}
}
