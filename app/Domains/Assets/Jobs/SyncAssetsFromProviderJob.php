<?php

namespace App\Domains\Assets\Jobs;

use App\Domains\Assets\Actions\SyncAssetFromIntegration;
use App\Domains\Assets\Exceptions\AssetLimitReachedException;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Models\TenantIntegration;
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
    ): void {
        $result = $providerAdapter->sync($this->integration, 'assets');

        foreach ($result['assets'] ?? [] as $assetData) {
            try {
                $syncAsset->execute(
                    $this->integration->team_id,
                    $this->integration->id,
                    $assetData,
                );
            } catch (AssetLimitReachedException) {
                // Tenant is at its asset cap: skip net-new assets and keep
                // processing the rest of the batch instead of failing the job.
                continue;
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->integration->update([
            'last_error_at' => now(),
            'last_error_message' => $exception->getMessage(),
        ]);
    }

    public function uniqueId(): string
    {
        return "sync-assets-{$this->integration->id}";
    }
}
