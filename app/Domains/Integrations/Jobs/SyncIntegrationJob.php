<?php

namespace App\Domains\Integrations\Jobs;

use App\Domains\Integrations\Actions\SyncIntegration;
use App\Domains\Integrations\Enums\SyncStatus;
use App\Domains\Integrations\Enums\SyncType;
use App\Domains\Integrations\Models\IntegrationSyncJob;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Support\SafeErrorMessage;
use App\Support\SystemLog;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Un solo sync en vuelo por integración: el alta (SyncCatalogOnIntegrationConnected)
 * y el scheduler (SyncDueIntegrationsJob) pueden pedirlo a la vez. Despachar
 * siempre vía {@see self::dispatchUnlessInFlight()}, que toma el candado único
 * ANTES de crear la fila de seguimiento: con un `dispatch()` a secas el
 * duplicado se descartaría en silencio y su `IntegrationSyncJob` quedaría
 * `pending` huérfano.
 */
class SyncIntegrationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 1800;

    /**
     * Lo más que un sync puede durar de verdad (3 × 1800 s + backoff): si el
     * worker muere sin pasar por failed(), el candado caduca a la vez que la
     * fila deja de contar como en vuelo para el scheduler.
     */
    public int $uniqueFor = SyncDueIntegrationsJob::STALE_SYNC_MINUTES * 60;

    public function __construct(
        public readonly TenantIntegration $integration,
        public readonly IntegrationSyncJob $syncJob,
    ) {
        $this->onQueue('sync');
    }

    /**
     * Crea la fila de seguimiento y despacha el sync, salvo que ya haya uno en
     * vuelo para la integración (devuelve null). Se llama dentro del
     * TenantContext de la integración.
     */
    public static function dispatchUnlessInFlight(TenantIntegration $integration, SyncType $type): ?IntegrationSyncJob
    {
        $syncJob = new IntegrationSyncJob([
            'tenant_integration_id' => $integration->id,
            'type' => $type,
            'status' => SyncStatus::Pending,
        ]);
        $job = new self($integration, $syncJob);
        $lock = new UniqueLock(app(Cache::class));

        if (! $lock->acquire($job)) {
            SystemLog::skipped('integrations.sync.dispatch', reason: 'sync_in_flight', input: [
                'team_id' => $integration->team_id,
                'integration_id' => $integration->id,
                'type' => $type->value,
            ]);

            return null;
        }

        try {
            $syncJob->save();
            // Bus::dispatch y no dispatch(): el candado ya es de este job, y
            // PendingDispatch intentaría tomarlo otra vez y lo descartaría.
            // Lo suelta el worker al terminar (o al fallar del todo).
            Bus::dispatch($job);
        } catch (Throwable $e) {
            $lock->release($job);

            throw $e;
        }

        return $syncJob;
    }

    public function handle(SyncIntegration $syncIntegration): void
    {
        $syncIntegration->execute($this->integration, $this->syncJob);
    }

    public function failed(Throwable $exception): void
    {
        $this->syncJob->markAsFailed(SafeErrorMessage::from($exception));
    }

    public function uniqueId(): string
    {
        return "sync-integration-{$this->integration->id}";
    }
}
