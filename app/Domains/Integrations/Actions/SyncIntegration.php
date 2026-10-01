<?php

namespace App\Domains\Integrations\Actions;

use App\Contracts\AssetSyncHandler;
use App\Contracts\DriverSyncHandler;
use App\Contracts\RawEventIngestion;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Events\IntegrationSyncCompleted;
use App\Domains\Integrations\Models\IntegrationSyncJob;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Support\SystemLog;
use App\Support\TenantContext;
use LogicException;

class SyncIntegration
{
    public function __construct(
        private ProviderAdapter $providerAdapter,
        private RawEventIngestion $rawEventIngestion,
        private AssetSyncHandler $assetSyncHandler,
        private DriverSyncHandler $driverSyncHandler,
    ) {}

    public function execute(TenantIntegration $integration, IntegrationSyncJob $syncJob): void
    {
        $syncJob->markAsRunning();

        try {
            $result = $this->providerAdapter->sync($integration, $syncJob->type->value);

            $this->forwardAssets($integration, $result['assets']);
            $this->forwardDrivers($integration, $result['drivers']);
            $this->forwardEvents($integration, $result['events']);

            $syncJob->markAsCompleted($result['records_processed']);

            $integration->update(['last_sync_at' => now()]);

            IntegrationSyncCompleted::dispatch(
                $integration->team_id,
                $integration->id,
                $syncJob->id,
                $result['records_processed'],
            );
        } catch (\Throwable $e) {
            $syncJob->markAsFailed($e->getMessage());

            $integration->update([
                'last_error_at' => now(),
                'last_error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $assets
     */
    private function forwardAssets(TenantIntegration $integration, array $assets): void
    {
        $counts = ['created' => 0, 'updated' => 0, 'conflict' => 0, 'not_handled' => 0];

        foreach ($assets as $assetData) {
            $outcome = $this->assetSyncHandler->syncFromIntegration(
                $integration->team_id,
                $integration->id,
                $assetData,
            );

            // null (o un valor fuera del contrato): la implementación no sincronizó.
            $counts[in_array($outcome, ['created', 'updated', 'conflict'], true) ? $outcome : 'not_handled']++;
        }

        TenantContext::for($integration->team_id, fn () => SystemLog::ok('assets.sync.completed', input: [
            'team_id' => $integration->team_id,
            'integration_id' => $integration->id,
            'stage' => 'integration_sync',
        ], result: [
            'assets_reported_count' => count($assets),
            'created_count' => $counts['created'],
            'updated_count' => $counts['updated'],
            'conflict_count' => $counts['conflict'],
            'not_handled_count' => $counts['not_handled'],
        ]));
    }

    /**
     * @param  array<int, array<string, mixed>>  $drivers
     */
    private function forwardDrivers(TenantIntegration $integration, array $drivers): void
    {
        foreach ($drivers as $driverData) {
            $this->driverSyncHandler->syncFromIntegration(
                $integration->team_id,
                $integration->id,
                $driverData,
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    private function forwardEvents(TenantIntegration $integration, array $events): void
    {
        // provider_id es FK NOT NULL con cascade: el proveedor siempre existe.
        $providerCode = $integration->provider?->code
            ?? throw new LogicException("TenantIntegration {$integration->id} sin IntegrationProvider.");

        foreach ($events as $eventData) {
            $this->rawEventIngestion->ingest(
                $integration->team_id,
                $providerCode,
                $eventData['event_type'] ?? 'unknown',
                $eventData,
            );
        }
    }
}
