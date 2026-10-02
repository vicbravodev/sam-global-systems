<?php

namespace App\Domains\Integrations\Jobs;

use App\Domains\Integrations\Enums\SyncStatus;
use App\Domains\Integrations\Models\IntegrationSyncJob;
use App\Support\ChunkedPurge;
use App\Support\JobFailureReporter;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Retención de `integration_sync_jobs` (`pipeline.retention.integration_sync_jobs_days`).
 *
 * Cada integración deja una fila por corrida de sync (varias por hora). Sólo
 * se leen las que están en vuelo (`SyncDueIntegrationsJob`) y la que acaba de
 * terminar (`NotifyPendingAssetsOnSyncCompleted`): las terminadas (`completed`,
 * `failed`) más viejas que la ventana no alimentan nada. Las `pending` y
 * `running` nunca se tocan.
 *
 * Recorrido de plataforma: cruza tenants a propósito y sólo registra conteos.
 */
class PurgeOldIntegrationSyncJobsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TABLE = 'integration_sync_jobs';

    private const CHUNK = 1000;

    /** @var list<SyncStatus> */
    public const PURGEABLE_STATUSES = [
        SyncStatus::Completed,
        SyncStatus::Failed,
    ];

    public function __construct(
        public readonly ?int $retentionDays = null,
    ) {
        $this->onQueue('sync');
    }

    public function handle(): int
    {
        $days = $this->retentionDays ?? (int) config('pipeline.retention.integration_sync_jobs_days', 30);
        $source = $this->retentionDays !== null ? 'argument' : 'config';

        if ($days < 1) {
            SystemLog::skipped('integrations.purge.completed', reason: 'disabled', input: ['table' => self::TABLE], calc: [
                'retention_days' => $days,
                'retention_source' => $source,
            ]);

            return 0;
        }

        $cutoff = now()->subDays($days);

        $outcome = TenantContext::withoutTenant(fn (): array => ChunkedPurge::run(
            IntegrationSyncJob::query()
                ->whereIn('status', self::PURGEABLE_STATUSES)
                ->where('created_at', '<', $cutoff),
            self::CHUNK,
        ));

        SystemLog::ok('integrations.purge.completed', input: ['table' => self::TABLE], calc: [
            'retention_days' => $days,
            'retention_source' => $source,
            'cutoff' => $cutoff->toIso8601String(),
            'chunk_size' => self::CHUNK,
            'statuses' => array_map(fn (SyncStatus $status): string => $status->value, self::PURGEABLE_STATUSES),
        ], result: [
            'removed_count' => $outcome['removed'],
            'batches_count' => $outcome['batches'],
        ]);

        return $outcome['removed'];
    }

    public function failed(Throwable $e): void
    {
        JobFailureReporter::report(static::class, $e);
    }
}
