<?php

namespace App\Domains\Integrations\Listeners;

use App\Domains\Integrations\Enums\SyncStatus;
use App\Domains\Integrations\Enums\SyncType;
use App\Domains\Integrations\Events\IntegrationConnected;
use App\Domains\Integrations\Jobs\SyncIntegrationJob;
use App\Domains\Integrations\Models\IntegrationSyncJob;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Support\SystemLog;
use App\Support\TenantContext;

class SyncCatalogOnIntegrationConnected
{
    public function handle(IntegrationConnected $event): void
    {
        // Lookup de entrada: el listener sólo tiene el id, y de la integración
        // sale el tenant. Ver §2.1.
        $integration = TenantIntegration::withoutGlobalScopes()->find($event->integrationId);

        if ($integration === null) {
            SystemLog::skipped('integrations.catalog_sync.requested', reason: 'integration_missing', input: ['integration_id' => $event->integrationId]);

            return;
        }

        $syncJob = IntegrationSyncJob::create([
            'tenant_integration_id' => $integration->id,
            'type' => SyncType::Full,
            'status' => SyncStatus::Pending,
        ]);

        SyncIntegrationJob::dispatch($integration, $syncJob);

        TenantContext::for($integration->team_id, fn () => SystemLog::ok('integrations.catalog_sync.requested', input: [
            'team_id' => $integration->team_id,
            'integration_id' => $integration->id,
        ], result: [
            'integration_sync_job_id' => $syncJob->id,
            'type' => SyncType::Full->value,
        ]));
    }
}
