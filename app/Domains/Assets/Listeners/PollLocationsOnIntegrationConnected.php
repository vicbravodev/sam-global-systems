<?php

namespace App\Domains\Assets\Listeners;

use App\Domains\Assets\Jobs\PollAssetLocationsJob;
use App\Domains\Integrations\Events\IntegrationConnected;
use App\Domains\Integrations\Models\TenantIntegration;

class PollLocationsOnIntegrationConnected
{
    public function handle(IntegrationConnected $event): void
    {
        $integration = TenantIntegration::withoutGlobalScopes()->find($event->integrationId);

        if ($integration === null) {
            return;
        }

        // Delay the first poll so the catalog backfill can create the assets the
        // incoming positions resolve against; otherwise every position is skipped.
        PollAssetLocationsJob::dispatch($integration)->delay(now()->addMinutes(2));
    }
}
