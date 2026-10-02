<?php

namespace App\Domains\Ingestion\Jobs;

use App\Domains\Ingestion\Actions\IngestAlertIncident;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Exceptions\ProviderCursorRejectedException;
use App\Domains\Integrations\Exceptions\ProviderRequestFailedException;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Support\JobFailureReporter;
use App\Support\PipelineTrace;
use App\Support\RedactSensitiveLogData;
use App\Support\SafeErrorMessage;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Respaldo del webhook de pánico para UNA integración Samsara: lee
 * `GET /alerts/incidents/stream` de sus alertas de pánico y mete cada
 * emergencia por el mismo pipeline que el webhook ({@see IngestAlertIncident}).
 *
 * Estado en `sync_state_json.alert_incidents`:
 * - `configuration_ids` / `configurations_refreshed_at`: alertas de pánico
 *   (trigger 1034) descubiertas con `GET /alerts/configurations`, cacheadas
 *   `configurations_refresh_minutes`. Sólo se refrescan entre barridos: un
 *   cursor vivo exige los mismos `configurationIds`.
 * - `cursor` / `start_time`: barrido a medias. Samsara exige en cada página
 *   el `startTime` byte a byte de la primera (bug del PR #129), así que se
 *   fija mientras viva el cursor.
 * - `covered_until`: hasta cuándo cubrió el último barrido completo.
 * - `last_polled_at`: último barrido completo.
 *
 * Cada barrido nuevo relee una ventana con solape (`window_minutes`, por
 * `updatedAtTime`), o desde `covered_until` si una caída dejó un hueco mayor,
 * nunca más atrás de {@see BACKFILL_HOURS}. El cursor se persiste sólo DESPUÉS
 * de guardar la página, y nunca con un error, así que un fallo relee y la
 * dedup por identidad absorbe la repetición.
 *
 * Único por integración: dos ticks solapados nunca consultan dos veces.
 */
class PollAlertIncidentsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Samsara: Panic Button = 1034 (WorkflowTriggerObject.triggerTypeId). */
    public const int PANIC_BUTTON_TRIGGER_TYPE_ID = 1034;

    /** `configurationIds` admite como máximo 50 ids por petición. */
    public const int MAX_CONFIGURATION_IDS = 50;

    public const int BACKFILL_HOURS = 24;

    /** Solape extra al reanudar desde `covered_until`. */
    public const int RESTART_MARGIN_MINUTES = 2;

    public const int RATE_LIMIT_FALLBACK_SECONDS = 30;

    public const string ERROR_PREFIX = 'Alert incidents poll: ';

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120];

    /** Debe quedar bajo el retry_after de `redis` (240 s). */
    public int $timeout = 120;

    public function __construct(
        public readonly TenantIntegration $integration,
    ) {
        $this->onQueue('ingestion');
    }

    public function uniqueId(): string
    {
        return "poll-alert-incidents-{$this->integration->id}";
    }

    public function handle(ProviderAdapter $providerAdapter, IngestAlertIncident $ingestAlertIncident): void
    {
        // El job entra con su integración: su team es el tenant de todo lo que sigue.
        TenantContext::set($this->integration->team_id);
        PipelineTrace::beginOperation($this->integration->team_id, 'samsara');

        $feed = $this->feed();
        $input = ['integration_id' => $this->integration->id];

        $cursor = $this->nonEmptyString($feed['cursor'] ?? null);
        $startTime = $this->nonEmptyString($feed['start_time'] ?? null);

        if ($cursor === null || $startTime === null) {
            $cursor = null;
            $startTime = null;
        }

        try {
            $configurationIds = $cursor !== null
                ? $this->stringList($feed['configuration_ids'] ?? [])
                : $this->panicConfigurationIds($providerAdapter, $feed);
        } catch (\Throwable $e) {
            $this->handleRequestFailure($e);

            return;
        }

        if ($configurationIds === []) {
            $this->persist($feed);

            SystemLog::skipped('ingestion.alert_incidents.skipped', reason: 'no_panic_configuration', input: $input, calc: [
                'panic_trigger_type_id' => self::PANIC_BUTTON_TRIGGER_TYPE_ID,
            ]);

            return;
        }

        $hadCursor = $cursor !== null;
        $startTime ??= $this->freshStartTime($feed);
        $maxPages = max(1, (int) config('pipeline.alert_incidents_poll.max_pages', 10));
        $counts = ['ingested' => 0, 'already_ingested' => 0, 'not_emergency' => 0];
        $pages = 0;
        $hasMore = false;

        while (true) {
            $requestedAt = now();

            try {
                $page = $providerAdapter->fetchAlertIncidents($this->integration, $configurationIds, $startTime, $cursor);
            } catch (ProviderCursorRejectedException $e) {
                if ($cursor === null) {
                    $this->handleRequestFailure($e);

                    return;
                }

                $restartFrom = $this->freshStartTime($feed);

                SystemLog::degraded('ingestion.alert_incidents.cursor_rejected', reason: 'provider_rejected_cursor', input: [
                    ...$input,
                    'http_status' => $e->status,
                    'provider_message' => Str::limit(RedactSensitiveLogData::sanitize((string) $e->providerMessage), 200),
                ], calc: ['restart_from' => $restartFrom]);

                $cursor = null;
                $startTime = $restartFrom;

                continue;
            } catch (\Throwable $e) {
                $this->handleRequestFailure($e);

                return;
            }

            foreach ($page['incidents'] as $incident) {
                $counts[$ingestAlertIncident->execute($this->integration, $incident)]++;
            }

            $pages++;
            $hasMore = $page['has_more'];

            // La página ya está guardada: recién ahora avanza el cursor.
            if ($hasMore) {
                $cursor = $page['cursor'];
                $feed['cursor'] = $cursor;
                $feed['start_time'] = $startTime;
                $feed['configuration_ids'] = $configurationIds;
            } else {
                $feed['cursor'] = null;
                $feed['start_time'] = null;
                $feed['covered_until'] = $requestedAt->toIso8601String();
                $feed['last_polled_at'] = now()->toIso8601String();
            }

            $this->persist($feed, clearError: true);

            if (! $hasMore || $pages >= $maxPages) {
                break;
            }
        }

        SystemLog::ok('ingestion.alert_incidents.cycle_completed', input: $input, calc: [
            'start_time' => $startTime,
            'had_cursor' => $hadCursor,
            'configurations_count' => count($configurationIds),
            'max_pages' => $maxPages,
        ], result: [
            'pages' => $pages,
            'has_more' => $hasMore,
            ...$counts,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, [
            'integration_id' => $this->integration->id,
        ]);

        $this->recordError($exception);
    }

    /**
     * Alertas de pánico del tenant, cacheadas. Si refrescar falla y hay caché,
     * se sigue con la caché (degradado): mejor consultar las de ayer que
     * dejar de vigilar pánicos.
     *
     * @param  array<string, mixed>  $feed
     * @return list<string>
     */
    private function panicConfigurationIds(ProviderAdapter $providerAdapter, array &$feed): array
    {
        $input = ['integration_id' => $this->integration->id];
        $cached = isset($feed['configuration_ids']) && is_array($feed['configuration_ids'])
            ? $this->stringList($feed['configuration_ids'])
            : null;
        $refreshMinutes = max(1, (int) config('pipeline.alert_incidents_poll.configurations_refresh_minutes', 60));
        $refreshedAt = $this->parseTime($feed['configurations_refreshed_at'] ?? null);

        if ($cached !== null && $refreshedAt !== null && $refreshedAt->greaterThan(now()->subMinutes($refreshMinutes))) {
            return $cached;
        }

        try {
            $configurations = $providerAdapter->fetchAlertConfigurations($this->integration);
        } catch (\Throwable $e) {
            if ($cached === null || ($e instanceof ProviderRequestFailedException && $e->isRateLimited())) {
                throw $e;
            }

            SystemLog::degraded('ingestion.alert_incidents.configurations_refreshed', reason: 'refresh_failed_using_cache', input: $input, calc: [
                'cached_count' => count($cached),
                'refresh_minutes' => $refreshMinutes,
            ], error: $e);

            return $cached;
        }

        $panic = [];

        foreach ($configurations as $configuration) {
            if ($configuration['is_enabled'] && in_array(self::PANIC_BUTTON_TRIGGER_TYPE_ID, $configuration['trigger_type_ids'], true)) {
                $panic[] = $configuration['id'];
            }
        }

        $panic = array_values(array_unique($panic));
        sort($panic);
        $truncated = count($panic) > self::MAX_CONFIGURATION_IDS;
        $panic = array_slice($panic, 0, self::MAX_CONFIGURATION_IDS);

        $feed['configuration_ids'] = $panic;
        $feed['configurations_refreshed_at'] = now()->toIso8601String();

        $calc = [
            'configurations_count' => count($configurations),
            'panic_count' => count($panic),
            'max_configuration_ids' => self::MAX_CONFIGURATION_IDS,
            'refresh_minutes' => $refreshMinutes,
        ];

        if ($truncated) {
            SystemLog::degraded('ingestion.alert_incidents.configurations_refreshed', reason: 'too_many_panic_configurations', input: $input, calc: $calc);
        } else {
            SystemLog::ok('ingestion.alert_incidents.configurations_refreshed', input: $input, calc: $calc);
        }

        return $panic;
    }

    /**
     * Inicio de un barrido nuevo: la ventana de solape, o más atrás si el
     * último barrido completo quedó antes (caída), nunca más de
     * {@see BACKFILL_HOURS}.
     *
     * @param  array<string, mixed>  $feed
     */
    private function freshStartTime(array $feed): string
    {
        $windowMinutes = max(1, (int) config('pipeline.alert_incidents_poll.window_minutes', 10));
        $candidate = now()->subMinutes($windowMinutes);
        $coveredUntil = $this->parseTime($feed['covered_until'] ?? null);

        if ($coveredUntil !== null) {
            $candidate = $candidate->min($coveredUntil->subMinutes(self::RESTART_MARGIN_MINUTES));
        }

        return $candidate->max(now()->subHours(self::BACKFILL_HOURS))->toIso8601String();
    }

    private function handleRequestFailure(\Throwable $e): void
    {
        $this->recordError($e);

        if ($e instanceof ProviderRequestFailedException && $e->isRateLimited()) {
            $releaseFor = $e->retryAfterSeconds ?? self::RATE_LIMIT_FALLBACK_SECONDS;

            SystemLog::degraded('ingestion.alert_incidents.rate_limited', reason: 'provider_rate_limited', input: [
                'integration_id' => $this->integration->id,
            ], calc: [
                'retry_after_seconds' => $e->retryAfterSeconds,
                'fallback_seconds' => self::RATE_LIMIT_FALLBACK_SECONDS,
                'released_for_seconds' => $releaseFor,
            ]);

            $this->release($releaseFor);

            return;
        }

        throw $e;
    }

    /**
     * Estado vigente del feed, releído de la base: otros pollers escriben
     * otras claves de `sync_state_json` mientras éste corre.
     *
     * @return array<string, mixed>
     */
    private function feed(): array
    {
        $state = $this->integration->fresh()?->sync_state_json ?? [];

        return (array) ($state['alert_incidents'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $feed
     */
    private function persist(array $feed, bool $clearError = false): void
    {
        $attributes = [];

        if ($clearError && str_starts_with((string) $this->integration->fresh()?->last_error_message, self::ERROR_PREFIX)) {
            $attributes['last_error_at'] = null;
            $attributes['last_error_message'] = null;
        }

        // Sólo su sub-clave, releída bajo lock: el poller de safety events
        // escribe la suya en paralelo.
        $this->integration->mergeSyncState('alert_incidents', $feed, $attributes);
    }

    private function recordError(\Throwable $exception): void
    {
        $this->integration->update([
            'last_error_at' => now(),
            'last_error_message' => self::ERROR_PREFIX.mb_substr(SafeErrorMessage::from($exception), 0, 500),
        ]);
    }

    private function parseTime(mixed $value): ?Carbon
    {
        $value = $this->nonEmptyString($value);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return array_values(array_filter((array) $value, fn (mixed $id): bool => is_string($id) && $id !== ''));
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
