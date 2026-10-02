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
            SystemLog::skipped('integrations.catalog_sync.skipped', reason: 'integration_not_found', input: [
                'team_id' => $event->teamId,
                'integration_id' => $event->integrationId,
            ]);

            return;
        }

        // El evento trae su team_id: si no es el dueño de la integración, no
        // se sincroniza el catálogo de otro tenant en su nombre.
        if ($integration->team_id !== $event->teamId) {
            SystemLog::skipped('integrations.catalog_sync.skipped', reason: 'team_mismatch', input: [
                'team_id' => $event->teamId,
                'integration_id' => $integration->id,
            ]);

            return;
        }

        // Dentro del tenant de la integración: el sync job se crea con el scope
        // aplicado y el SyncIntegrationJob viaja con su TenantContext, aunque
        // aquí no haya usuario autenticado (cola, consola, scheduler).
        TenantContext::for($integration->team_id, function () use ($integration): void {
            $syncJob = IntegrationSyncJob::query()->create([
                'tenant_integration_id' => $integration->id,
                'type' => SyncType::Full,
                'status' => SyncStatus::Pending,
            ]);

            SyncIntegrationJob::dispatch($integration, $syncJob);

            SystemLog::ok('integrations.catalog_sync.queued', input: [
                'team_id' => $integration->team_id,
                'integration_id' => $integration->id,
            ], result: [
                'sync_job_id' => $syncJob->id,
            ]);
        });
    }
}
