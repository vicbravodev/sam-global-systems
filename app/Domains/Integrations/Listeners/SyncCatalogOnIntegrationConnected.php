<?php

namespace App\Domains\Integrations\Listeners;

use App\Domains\Integrations\Enums\SyncStatus;
use App\Domains\Integrations\Enums\SyncType;
use App\Domains\Integrations\Events\IntegrationConnected;
use App\Domains\Integrations\Jobs\SyncIntegrationJob;
use App\Domains\Integrations\Models\IntegrationSyncJob;
use App\Domains\Integrations\Models\TenantIntegration;

class SyncCatalogOnIntegrationConnected
{
    public function handle(IntegrationConnected $event): void
    {
        // Lookup de entrada: el listener sólo tiene el id, y de la integración
        // sale el tenant. Ver §2.1.
        $integration = TenantIntegration::withoutGlobalScopes()->find($event->integrationId);

        if ($integration === null) {
            return;
        }

        $syncJob = IntegrationSyncJob::create([
            'tenant_integration_id' => $integration->id,
            'type' => SyncType::Full,
            'status' => SyncStatus::Pending,
        ]);

        SyncIntegrationJob::dispatch($integration, $syncJob);
    }
}
