<?php

namespace App\Domains\Ingestion\Jobs;

use App\Domains\Ingestion\Actions\AlertPipelineFailure;
use App\Domains\Ingestion\Actions\IngestSafetyEvent;
use App\Domains\Ingestion\Actions\QueueRawEventForProcessing;
use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Models\PipelineFailureAlert;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Actions\ClassifyRawEventEmergency;
use App\Support\JobFailureReporter;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Red de seguridad del pipeline: rescata los raw events que fallaron o se
 * quedaron sin avanzar (worker muerto entre etapas, job perdido, reintentos
 * agotados por una caída pasajera) y los vuelve a meter por la entrada normal
 * (ProcessRawEventJob). Emergencias primero.
 *
 * Atascado = `failed`, `received`, `pending_processing` o `processing` sin
 * cambios desde hace `pipeline.reprocess.stuck_after_minutes`, o `processed`
 * sin evento normalizado en ese plazo (la normalización nunca llegó). Nunca se
 * rescata lo recibido hace más de `max_age_hours` (backlog, no noticia).
 *
 * Tope: `raw_events.reprocess_attempts` cuenta rescates; al llegar a
 * `max_attempts` se deja de reintentar y se alerta una sola vez
 * (AlertPipelineFailure). Cada rescate toca `updated_at`, así que el propio
 * umbral hace de espera entre rescates.
 *
 * Idempotente de punta a punta: el dedup de ingestión reconoce la clave del
 * propio evento, la normalización es updateOrCreate por `raw_event_id` y el
 * incidente se reencuentra por evento (findExistingFor) antes de crearse.
 *
 * Tenant: el recorrido de plataforma sólo descubre qué teams tienen atascados;
 * la búsqueda, la clasificación y el re-despacho de cada evento corren dentro
 * de TenantContext::for($teamId), así que el job despachado viaja con el
 * tenant del evento.
 */
class ReprocessStuckRawEventsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    /** @var list<RawEventStatus> */
    public const array STUCK_STATUSES = [
        RawEventStatus::Failed,
        RawEventStatus::Received,
        RawEventStatus::PendingProcessing,
        RawEventStatus::Processing,
    ];

    public function __construct()
    {
        $this->onQueue('ingestion');
    }

    public function handle(
        QueueRawEventForProcessing $queueForProcessing,
        ClassifyRawEventEmergency $classify,
        AlertPipelineFailure $alert,
    ): void {
        $config = [
            'stuck_after_minutes' => max(1, (int) config('pipeline.reprocess.stuck_after_minutes', 15)),
            'max_attempts' => max(1, (int) config('pipeline.reprocess.max_attempts', 3)),
            'max_age_hours' => max(1, (int) config('pipeline.reprocess.max_age_hours', 24)),
            'batch_size' => max(1, (int) config('pipeline.reprocess.batch_size', 100)),
        ];

        // Recorrido de plataforma: sólo qué teams tienen algo atascado.
        $teamIds = TenantContext::withoutTenant(fn () => $this->stuckQuery($config)
            ->whereNotNull('team_id')
            ->distinct()
            ->pluck('team_id')
            ->map(fn ($id) => (int) $id)
            ->all());

        /** @var list<array{team_id: int, raw_event_id: int, emergency: bool, received_at: int}> $retryable */
        $retryable = [];
        $exhausted = 0;

        foreach ($teamIds as $teamId) {
            TenantContext::for($teamId, function () use ($config, $classify, $alert, $teamId, &$retryable, &$exhausted): void {
                $events = $this->stuckQuery($config)
                    ->where('team_id', $teamId)
                    ->whereNotExists(fn (QueryBuilder $q) => $q
                        ->selectRaw('1')
                        ->from('pipeline_failure_alerts')
                        ->whereColumn('pipeline_failure_alerts.raw_event_id', 'raw_events.id')
                        ->where('pipeline_failure_alerts.kind', PipelineFailureAlert::KIND_REPROCESS_EXHAUSTED))
                    ->orderBy('received_at')
                    ->limit($config['batch_size'])
                    ->get();

                foreach ($events as $event) {
                    if ($event->reprocess_attempts >= $config['max_attempts']) {
                        SystemLog::failed('ingestion.reprocess.exhausted', reason: 'max_reprocess_attempts', input: [
                            'raw_event_id' => $event->id,
                            'status' => $event->status->value,
                        ], calc: ['reprocess_attempts' => $event->reprocess_attempts, 'max_attempts' => $config['max_attempts']]);

                        $alert->forExhaustedReprocess($event);
                        $exhausted++;

                        continue;
                    }

                    $retryable[] = [
                        'team_id' => $teamId,
                        'raw_event_id' => $event->id,
                        'emergency' => $classify->execute($event)['emergency'],
                        'received_at' => $event->received_at->getTimestamp(),
                    ];
                }
            });
        }

        // Emergencias primero, después las más antiguas, entre todos los tenants.
        usort($retryable, fn (array $a, array $b) => [$b['emergency'], $a['received_at']] <=> [$a['emergency'], $b['received_at']]);

        $dispatched = 0;
        $emergencies = 0;

        foreach (array_slice($retryable, 0, $config['batch_size']) as $candidate) {
            $done = TenantContext::for($candidate['team_id'], fn () => $this->reprocess($candidate, $config, $queueForProcessing));

            if ($done) {
                $dispatched++;
                $emergencies += $candidate['emergency'] ? 1 : 0;
            }
        }

        // Sólo conteos: nunca ids de un tenant en la línea de plataforma.
        SystemLog::ok('ingestion.reprocess_sweep.completed', calc: $config, result: [
            'teams_count' => count($teamIds),
            'candidates_count' => count($retryable),
            'dispatched_count' => $dispatched,
            'emergency_dispatched_count' => $emergencies,
            'deferred_count' => max(0, count($retryable) - $config['batch_size']),
            'exhausted_count' => $exhausted,
        ]);
    }

    /**
     * @param  array{team_id: int, raw_event_id: int, emergency: bool, received_at: int}  $candidate
     * @param  array{stuck_after_minutes: int, max_attempts: int, max_age_hours: int, batch_size: int}  $config
     */
    private function reprocess(array $candidate, array $config, QueueRawEventForProcessing $queueForProcessing): bool
    {
        // Re-lectura bajo el tenant activo: si otro worker lo movió entre la
        // búsqueda y aquí, ya no está atascado y no se toca.
        $event = $this->stuckQuery($config)
            ->where('team_id', $candidate['team_id'])
            ->whereKey($candidate['raw_event_id'])
            ->first();

        if ($event === null) {
            SystemLog::skipped('ingestion.reprocess.skipped', reason: 'no_longer_stuck', input: ['raw_event_id' => $candidate['raw_event_id']]);

            return false;
        }

        $previousStatus = $event->status->value;
        $attempt = $event->reprocess_attempts + 1;

        $event->forceFill([
            'reprocess_attempts' => $attempt,
            'last_reprocessed_at' => now(),
        ])->save();

        $queueForProcessing->execute($event);

        SystemLog::ok('ingestion.reprocess.dispatched', input: [
            'raw_event_id' => $event->id,
            'previous_status' => $previousStatus,
        ], calc: [
            'is_emergency' => $candidate['emergency'],
            'reprocess_attempt' => $attempt,
            'max_attempts' => $config['max_attempts'],
            'stuck_after_minutes' => $config['stuck_after_minutes'],
        ]);

        return true;
    }

    /**
     * @param  array{stuck_after_minutes: int, max_attempts: int, max_age_hours: int, batch_size: int}  $config
     * @return Builder<RawEvent>
     */
    private function stuckQuery(array $config): Builder
    {
        return RawEvent::query()
            ->where('updated_at', '<', now()->subMinutes($config['stuck_after_minutes']))
            ->where('received_at', '>=', now()->subHours($config['max_age_hours']))
            ->where(fn (Builder $q) => $q
                ->whereIn('status', self::STUCK_STATUSES)
                ->orWhere(fn (Builder $processed) => $processed
                    ->where('status', RawEventStatus::Processed)
                    ->whereNotExists(fn (QueryBuilder $n) => $n
                        ->selectRaw('1')
                        ->from('normalized_events')
                        ->whereColumn('normalized_events.raw_event_id', 'raw_events.id'))
                    // Un estado de safety event cuya entidad ya pasó a un
                    // estado más nuevo (NormalizeRawEvent::updateProviderEntity
                    // mueve `raw_event_id`) sí se normalizó: no está atascado.
                    ->whereNot(fn (Builder $safety) => $safety
                        ->where('deduplication_key', 'like', IngestSafetyEvent::KEY_PREFIX.'%')
                        ->whereExists(fn (QueryBuilder $n) => $n
                            ->selectRaw('1')
                            ->from('normalized_events')
                            ->whereColumn('normalized_events.team_id', 'raw_events.team_id')
                            ->whereRaw('normalized_events.provider_event_key = ? || raw_events.external_event_id', [IngestSafetyEvent::KEY_PREFIX])))));
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception);
    }
}
