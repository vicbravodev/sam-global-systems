<?php

namespace App\Domains\Assets\Listeners;

use App\Domains\Assets\Actions\NotifyPendingAssets;
use App\Domains\Assets\Models\Asset;
use App\Domains\Integrations\Events\IntegrationSyncCompleted;
use App\Domains\Integrations\Models\IntegrationSyncJob;
use App\Support\TenantContext;

/**
 * En cuanto se sincronizan las entidades principales, el admin de la empresa
 * se entera de las unidades nuevas que quedaron en `pending` (sin vigilar ni
 * cobrar hasta que las encienda). Antes el aviso existía pero el sync real
 * nunca lo disparaba y 20 tractos nuevos quedaban sin vigilancia en silencio.
 */
class NotifyPendingAssetsOnSyncCompleted
{
    public function __construct(
        private readonly NotifyPendingAssets $notifyPendingAssets,
    ) {}

    public function handle(IntegrationSyncCompleted $event): void
    {
        TenantContext::for($event->teamId, function () use ($event) {
            $startedAt = IntegrationSyncJob::query()
                ->whereKey($event->syncJobId)
                ->where('tenant_integration_id', $event->integrationId)
                ->value('started_at');

            if ($startedAt === null) {
                return;
            }

            $newlyPending = Asset::query()
                ->where('team_id', $event->teamId)
                ->pendingMonitoring()
                ->where('created_at', '>=', $startedAt)
                ->count();

            $this->notifyPendingAssets->execute($event->teamId, $newlyPending);
        });
    }
}
