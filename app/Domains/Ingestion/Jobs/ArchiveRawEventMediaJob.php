<?php

namespace App\Domains\Ingestion\Jobs;

use App\Concerns\DefersOnObjectStorageOutage;
use App\Domains\Context\Jobs\ExtractEventMediaJob;
use App\Domains\Ingestion\Actions\ArchiveRawEventInlineMedia;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\JobFailureReporter;
use App\Support\PipelineTrace;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Archivado diferido de la media inline de un safety event cuando el storage
 * de objetos (RustFS/S3) no estaba disponible al ingerirlo. El evento ya se
 * procesó con lo que hay en DB; esto sólo completa la evidencia.
 *
 * Mientras el storage siga caído el job se re-encola solo con backoff
 * exponencial ({@see DefersOnObjectStorageOutage}): un `release()` no cuenta
 * como excepción, así que sólo `$maxExceptions` fallos de otro tipo lo
 * matan. Las URLs se releen del `payload_json` persistido (no se guardan en
 * ningún otro sitio); si para entonces caducaron, el descargador lo registra
 * y el job termina.
 *
 * Cuando archiva algo y el evento ya está normalizado, dispara
 * {@see ExtractEventMediaJob} (idempotente) para que la media llegue al
 * contexto, la galería y la evaluación visual.
 *
 * Cola `context` (supervisor medium): es trabajo de media, no de ingesta, y
 * no debe ocupar el pool `high` durante una caída larga.
 */
class ArchiveRawEventMediaJob implements ShouldQueue
{
    use DefersOnObjectStorageOutage, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $maxExceptions = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    /** Debe quedar por debajo del `retry_after` de `redis` (240 s). */
    public int $timeout = 200;

    public function __construct(
        public readonly int $rawEventId,
        public readonly int $teamId,
    ) {
        $this->onQueue('context');
    }

    public function handle(ArchiveRawEventInlineMedia $archiveMedia): void
    {
        // Lookup de entrada sin scope: el job descubre su tenant por el evento.
        $rawEvent = RawEvent::withoutGlobalScopes()->find($this->rawEventId);

        if ($rawEvent === null) {
            SystemLog::skipped('ingestion.media.archive_skipped', reason: 'raw_event_missing', input: ['raw_event_id' => $this->rawEventId]);

            return;
        }

        if ($rawEvent->team_id !== $this->teamId) {
            SystemLog::skipped('ingestion.media.archive_skipped', reason: 'team_mismatch', input: ['raw_event_id' => $this->rawEventId, 'job_team_id' => $this->teamId]);

            return;
        }

        PipelineTrace::adopt($rawEvent->trace_id, $rawEvent->team_id, ['raw_event_id' => $rawEvent->id]);

        TenantContext::for($rawEvent->team_id, fn () => $this->archive($rawEvent, $archiveMedia));
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, [
            'raw_event_id' => $this->rawEventId,
            'team_id' => $this->teamId,
        ]);
    }

    private function archive(RawEvent $rawEvent, ArchiveRawEventInlineMedia $archiveMedia): void
    {
        $result = $archiveMedia->execute($rawEvent);

        if ($result['storage_unavailable']) {
            $this->deferForObjectStorageOutage('ingestion.media.archive_deferred', ['raw_event_id' => $rawEvent->id, 'trigger' => 'retry_storage_failed'], $result['storage_error']);

            return;
        }

        $normalizedEventId = $result['downloaded'] > 0
            ? NormalizedEvent::query()->where('raw_event_id', $rawEvent->id)->value('id')
            : null;

        if ($normalizedEventId !== null) {
            ExtractEventMediaJob::dispatch((int) $normalizedEventId);
        }

        SystemLog::ok('ingestion.media.archived', input: ['raw_event_id' => $rawEvent->id], calc: ['attempt' => $this->attempts()], result: [
            'found' => $result['found'],
            'downloaded' => $result['downloaded'],
            'already_stored' => $result['already_stored'],
            'failed' => $result['failed'],
            'extract_dispatched' => $normalizedEventId !== null,
        ]);
    }
}
