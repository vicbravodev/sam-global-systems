<?php

namespace App\Domains\Assets\Jobs;

use App\Domains\Assets\Actions\NotifyPendingAssets;
use App\Domains\Assets\Actions\SyncAssetFromIntegration;
use App\Domains\Assets\Exceptions\AssetExternalReferenceConflictException;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Support\SafeErrorMessage;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncAssetsFromProviderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 1800;

    public function __construct(
        public readonly TenantIntegration $integration,
    ) {
        $this->onQueue('sync');
    }

    public function handle(
        ProviderAdapter $providerAdapter,
        SyncAssetFromIntegration $syncAsset,
        NotifyPendingAssets $notifyPending,
    ): void {
        $result = $providerAdapter->sync($this->integration, 'assets');
        $discovered = 0;
        $assets = $result['assets'];
        $counts = ['created' => 0, 'updated' => 0, 'conflict' => 0];

        foreach ($assets as $assetData) {
            try {
                $asset = $syncAsset->execute(
                    $this->integration->team_id,
                    $this->integration->id,
                    $assetData,
                );

                if ($asset->wasRecentlyCreated) {
                    $discovered++;
                }

                $counts[$asset->wasRecentlyCreated ? 'created' : 'updated']++;
            } catch (AssetExternalReferenceConflictException $e) {
                // The provider handed us an external id another tenant already
                // owns: skip that asset rather than touching their data, and
                // keep syncing the rest of the batch.
                $e->logSkipped();
                $counts['conflict']++;

                continue;
            }
        }

        // Una sola notificación por corrida de sync (no una por unidad): el
        // cliente decide cuáles enciende sabiendo cuánto cupo le queda.
        if ($discovered > 0) {
            $notifyPending->execute($this->integration->team_id, $discovered);
        }

        TenantContext::for($this->integration->team_id, fn () => SystemLog::ok('assets.sync.completed', input: [
            'team_id' => $this->integration->team_id,
            'integration_id' => $this->integration->id,
            'stage' => 'provider_job',
        ], result: [
            'assets_reported_count' => count($assets),
            'created_count' => $counts['created'],
            'updated_count' => $counts['updated'],
            'conflict_count' => $counts['conflict'],
            'not_handled_count' => 0,
            'discovered_count' => $discovered,
        ]));
    }

    public function failed(\Throwable $exception): void
    {
        $this->integration->update([
            'last_error_at' => now(),
            'last_error_message' => SafeErrorMessage::from($exception),
        ]);
    }

    public function uniqueId(): string
    {
        return "sync-assets-{$this->integration->id}";
    }
}
