<?php

namespace App\Domains\Integrations\Jobs;

use App\Domains\Integrations\Enums\WebhookEventStatus;
use App\Domains\Integrations\Models\WebhookEvent;
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
 * Retención de `webhook_events` (`pipeline.retention.webhook_events_days`).
 *
 * Cada webhook entrante deja una fila con el cuerpo crudo y su firma. Una vez
 * resuelto (`processed`, `failed`, `invalid_signature`) nada la vuelve a leer:
 * lo procesado ya vive en `raw_events` y lo rechazado no se reintenta. Los
 * `received`/`processing` se conservan: uno atascado es para investigar.
 *
 * Recorrido de plataforma: cruza tenants a propósito y sólo registra conteos.
 */
class PurgeOldWebhookEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TABLE = 'webhook_events';

    private const CHUNK = 1000;

    /** @var list<WebhookEventStatus> */
    public const PURGEABLE_STATUSES = [
        WebhookEventStatus::Processed,
        WebhookEventStatus::Failed,
        WebhookEventStatus::InvalidSignature,
    ];

    public function __construct(
        public readonly ?int $retentionDays = null,
    ) {
        $this->onQueue('sync');
    }

    public function handle(): int
    {
        $days = $this->retentionDays ?? (int) config('pipeline.retention.webhook_events_days', 30);
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
            WebhookEvent::query()
                ->whereIn('status', self::PURGEABLE_STATUSES)
                ->where('received_at', '<', $cutoff),
            self::CHUNK,
        ));

        SystemLog::ok('integrations.purge.completed', input: ['table' => self::TABLE], calc: [
            'retention_days' => $days,
            'retention_source' => $source,
            'cutoff' => $cutoff->toIso8601String(),
            'chunk_size' => self::CHUNK,
            'statuses' => array_map(fn (WebhookEventStatus $status): string => $status->value, self::PURGEABLE_STATUSES),
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
