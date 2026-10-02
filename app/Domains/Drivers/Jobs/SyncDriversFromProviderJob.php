<?php

namespace App\Domains\Drivers\Jobs;

use App\Domains\Drivers\Actions\SyncDriverFromIntegration;
use App\Domains\Drivers\Exceptions\DriverExternalReferenceConflictException;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Support\SafeErrorMessage;
use App\Support\SystemLog;
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
        $started = hrtime(true);
        $result = $providerAdapter->sync($this->integration, 'drivers');
        $synced = 0;
        $conflicts = 0;

        foreach ($result['drivers'] as $driverData) {
            try {
                $syncDriver->execute(
                    $this->integration->team_id,
                    $this->integration->id,
                    $driverData,
                );
                $synced++;
            } catch (DriverExternalReferenceConflictException $e) {
                // The provider handed us an external id another tenant already
                // owns: skip that driver rather than touching their data, and
                // keep syncing the rest of the batch.
                $e->logSkipped();
                $conflicts++;
            }
        }

        SystemLog::ok('drivers.sync.completed', input: [
            'team_id' => $this->integration->team_id,
            'integration_id' => $this->integration->id,
        ], result: [
            'received' => count($result['drivers']),
            'synced' => $synced,
            'external_id_conflicts' => $conflicts,
        ], durationMs: SystemLog::elapsedMs($started));
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
        return "sync-drivers-{$this->integration->id}";
    }
}
