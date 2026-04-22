<?php

namespace App\Domains\Integrations\Actions;

use App\Contracts\AssetSyncHandler;
use App\Contracts\DriverSyncHandler;
use App\Contracts\RawEventIngestion;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Events\IntegrationSyncCompleted;
use App\Domains\Integrations\Models\IntegrationSyncJob;
use App\Domains\Integrations\Models\TenantIntegration;

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
        foreach ($assets as $assetData) {
            $this->assetSyncHandler->syncFromIntegration(
                $integration->team_id,
                $integration->id,
                $assetData,
            );
        }
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
        foreach ($events as $eventData) {
            $this->rawEventIngestion->ingest(
                $integration->team_id,
                $integration->provider->code,
                $eventData['event_type'] ?? 'unknown',
                $eventData,
            );
        }
    }
}
