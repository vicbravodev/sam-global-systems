<?php

namespace App\Domains\Drivers\Jobs;

use App\Domains\Drivers\Actions\SyncDriverFromIntegration;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Models\TenantIntegration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncDriversFromProviderJob implements ShouldQueue
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
        SyncDriverFromIntegration $syncDriver,
    ): void {
        $result = $providerAdapter->sync($this->integration, 'drivers');

        foreach ($result['drivers'] ?? [] as $driverData) {
            $syncDriver->execute(
                $this->integration->team_id,
                $this->integration->id,
                $driverData,
            );
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
        return "sync-drivers-{$this->integration->id}";
    }
}
