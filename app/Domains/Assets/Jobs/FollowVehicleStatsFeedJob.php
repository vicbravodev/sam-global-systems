<?php

namespace App\Domains\Assets\Jobs;

use App\Contracts\TenantConfig\TenantScheduleResolver;
use App\Domains\Assets\Actions\IngestVehicleStatsPage;
use App\Domains\Assets\Actions\RaiseAfterHoursMovement;
use App\Domains\Assets\Data\VehicleStatsIngestResult;
use App\Domains\Assets\Enums\TelematicsFeed;
use App\Domains\Assets\Events\FleetPositionsUpdatedBroadcast;
use App\Domains\Assets\Events\FleetTelemetryUpdatedBroadcast;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\TelematicsFeedCursor;
use App\Domains\Assets\Support\MovementCriterion;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Events\IntegrationStatusChanged;
use App\Domains\Integrations\Exceptions\ProviderCursorRejected;
use App\Domains\Integrations\Exceptions\ProviderRateLimited;
use App\Domains\Integrations\Exceptions\ProviderRequestFailed;
use App\Domains\Integrations\Exceptions\ProviderUnauthorized;
use App\Domains\Integrations\Exceptions\ProviderUnavailable;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Support\PipelineTrace;
use App\Support\SafeErrorMessage;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One cycle of one tenant's stats feed: follow the provider's cursor page by
 * page, store what arrived, then push it to the tenant's sockets and run the
 * inline detectors.
 *
 * Every page commits in one transaction together with the cursor that covers
 * it, so a crash between pages replays at most one page (which the unique
 * indexes absorb) and never skips data. Failures never block other tenants:
 * they only pause this feed, recorded on its cursor row.
 *
 * Unique per (integration, feed): a cycle still running when the next tick
 * comes is not queued twice.
 */
class FollowVehicleStatsFeedJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Failures are handled in-cycle (pause + backoff); the next tick retries. */
    public int $tries = 1;

    public int $timeout = 55;

    /** Releases the lock if a worker dies mid-cycle. */
    public int $uniqueFor = 60;

    /** Positions per socket message, to stay well under the payload limit. */
    private const BROADCAST_CHUNK = 200;

    public function __construct(
        public readonly TenantIntegration $integration,
        public readonly TelematicsFeed $feed,
    ) {
        $this->onQueue('telematics');
    }

    public function handle(
        ProviderAdapter $providerAdapter,
        IngestVehicleStatsPage $ingest,
        TenantScheduleResolver $scheduleResolver,
        RaiseAfterHoursMovement $raiseAfterHours,
    ): void {
        TenantContext::set($this->integration->team_id);
        // Cada ciclo del feed es una operación; los eventos que levante (fuera
        // de horario) abren su propia traza hija. Sin query extra: hot path.
        PipelineTrace::beginOperation(
            $this->integration->team_id,
            $this->integration->relationLoaded('provider') ? $this->integration->provider?->code : null,
        );

        $cursor = TelematicsFeedCursor::query()->firstOrCreate(
            ['tenant_integration_id' => $this->integration->id, 'feed' => $this->feed],
            ['team_id' => $this->integration->team_id],
        );

        $cycleInput = ['integration_id' => $this->integration->id, 'feed' => $this->feed->value];

        if ($cursor->isPaused()) {
            // Rare: the dispatcher already skips paused cursors.
            SystemLog::skipped('telematics.cycle.paused', reason: 'paused', input: $cycleInput, calc: [
                'paused_until' => $cursor->paused_until->toIso8601String(),
                'remaining_s' => (int) now()->diffInSeconds($cursor->paused_until),
                'consecutive_failures' => $cursor->consecutive_failures,
            ], channel: 'telematics');

            return;
        }

        $startedAt = now();
        $initialCursor = $cursor->end_cursor;
        $cursor->forceFill(['last_polled_at' => $startedAt])->save();

        $result = new VehicleStatsIngestResult;
        $pages = 0;
        $failure = null;
        $failureInfo = null;
        $lastHasNextPage = false;
        $maxPages = max(1, (int) config('telematics.max_pages_per_cycle', 20));

        try {
            do {
                $page = $providerAdapter->fetchVehicleStatsFeed($this->integration, $this->feed, $cursor->end_cursor);

                $pageResult = DB::transaction(function () use ($ingest, $page, $cursor) {
                    $pageResult = $ingest->execute($this->integration, $page);

                    $cursor->forceFill([
                        'end_cursor' => $page->endCursor ?? $cursor->end_cursor,
                        'last_data_at' => $this->later($cursor->last_data_at, $pageResult->newestPointAt),
                    ])->save();

                    return $pageResult;
                });

                $result = $result->merge($pageResult);
                $pages++;
                $lastHasNextPage = $page->hasNextPage;

                $this->logDroppedPoints($pageResult, $cycleInput, $pages);
            } while ($page->hasNextPage && $pages < $maxPages);
        } catch (ProviderRequestFailed $e) {
            $failure = $e;
            $failureInfo = $this->handleFailure($cursor, $e);
        }

        if ($failure === null) {
            $cursor->forceFill([
                'consecutive_failures' => 0,
                'paused_until' => null,
                'last_error' => null,
                'last_success_at' => now(),
            ]);
        }

        $durationMs = (int) $startedAt->diffInMilliseconds(now());

        $cursor->forceFill(['last_cycle_json' => [
            'duration_ms' => $durationMs,
            'pages' => $pages,
            'locations' => $result->locationsStored,
            'readings' => $result->readingsStored,
            'error' => $failure !== null ? class_basename($failure) : null,
        ]])->save();

        // Whatever pages committed before a failure are real data: publish
        // them and run the detectors over them all the same.
        $this->publish($result);

        if ($this->feed === TelematicsFeed::Motion && $result->positions !== []) {
            $this->detectAfterHoursMovement($result, $scheduleResolver, $raiseAfterHours, $cycleInput);
        }

        $droppedByReason = [];

        foreach ($result->dropped as $reason => $count) {
            if ($count > 0) {
                $droppedByReason[$reason.'_count'] = $count;
            }
        }

        $cycleCalc = [
            'max_pages_per_cycle' => $maxPages,
            'max_pages_hit' => $failure === null && $pages >= $maxPages && $lastHasNextPage,
            'dropped_count_by_reason' => $droppedByReason,
        ];
        // A rejected cursor is dropped (reset to null), not moved forward: it
        // never reads as progress.
        $cursorReset = ($failureInfo['reason'] ?? null) === 'cursor_rejected';
        $cycleResult = [
            'pages' => $pages,
            'locations' => $result->locationsStored,
            'readings' => $result->readingsStored,
            'moved_assets' => count($result->positions),
            'lag_s' => $cursor->lagSeconds(),
            'cursor_advanced' => ! $cursorReset && $cursor->end_cursor !== $initialCursor,
            'cursor_reset' => $cursorReset,
        ];

        if ($failure === null) {
            SystemLog::ok('telematics.cycle.completed', input: $cycleInput, calc: $cycleCalc, result: $cycleResult, durationMs: $durationMs, channel: 'telematics');
        } else {
            SystemLog::degraded('telematics.cycle.failed', reason: $failureInfo['reason'], input: $cycleInput, calc: [...$cycleCalc, ...$failureInfo], result: $cycleResult, error: $failure, durationMs: $durationMs, channel: 'telematics');
        }
    }

    /**
     * One line per reason a page dropped points. Replays, unchanged readings
     * and vehicles without a monitored asset of this tenant are routine every
     * cycle, so they stay at debug; the rest point at bad provider data.
     *
     * @param  array{integration_id: int, feed: string}  $cycleInput
     */
    private function logDroppedPoints(VehicleStatsIngestResult $pageResult, array $cycleInput, int $page): void
    {
        foreach ($pageResult->dropped as $reason => $count) {
            if ($count <= 0) {
                continue;
            }

            SystemLog::skipped(
                'telematics.points.dropped',
                reason: $reason,
                input: [...$cycleInput, 'page' => $page],
                calc: ['dropped_count' => $count],
                debug: in_array($reason, ['already_stored', 'unchanged_value', 'no_monitored_asset'], true),
                channel: 'telematics',
            );
        }
    }

    public function uniqueId(): string
    {
        return "telematics-feed:{$this->integration->id}:{$this->feed->value}";
    }

    /**
     * Pause, restart or open the circuit per failure class, and return the
     * terms of that decision for the cycle line.
     *
     * @return array{reason: 'rate_limited'|'provider_unavailable'|'cursor_rejected'|'unauthorized'|'provider_error', failure_class: string, consecutive_failures: int, retry_after_s: ?float, pause_s: ?int, backoff_base_s: ?int, backoff_max_s: ?int, paused_until: ?string, backfill_requested: bool, circuit_opened: bool}
     */
    private function handleFailure(TelematicsFeedCursor $cursor, ProviderRequestFailed $e): array
    {
        $failures = $cursor->consecutive_failures + 1;

        $cursor->forceFill([
            'consecutive_failures' => $failures,
            'last_error' => SafeErrorMessage::from($e),
        ]);

        $pauseSeconds = match (true) {
            // Whole seconds, rounded up: the column has no fractions, and
            // truncating would resume before the provider allows.
            $e instanceof ProviderRateLimited => (int) ceil(max(1.0, $e->retryAfterSeconds)),
            $e instanceof ProviderUnavailable => $this->backoffSeconds($failures),
            default => null,
        };
        $backfillRequested = false;

        match (true) {
            $e instanceof ProviderRateLimited,
            $e instanceof ProviderUnavailable => $cursor->forceFill([
                'paused_until' => now()->addSeconds($pauseSeconds),
            ]),
            $e instanceof ProviderCursorRejected => $backfillRequested = $this->restartFromHistory($cursor),
            $e instanceof ProviderUnauthorized => $this->openCircuit($e),
            default => null,
        };

        $unavailable = $e instanceof ProviderUnavailable;

        return [
            'reason' => match (true) {
                $e instanceof ProviderRateLimited => 'rate_limited',
                $e instanceof ProviderUnavailable => 'provider_unavailable',
                $e instanceof ProviderCursorRejected => 'cursor_rejected',
                $e instanceof ProviderUnauthorized => 'unauthorized',
                default => 'provider_error',
            },
            'failure_class' => class_basename($e),
            'consecutive_failures' => $cursor->consecutive_failures,
            'retry_after_s' => $e instanceof ProviderRateLimited ? $e->retryAfterSeconds : null,
            'pause_s' => $pauseSeconds,
            'backoff_base_s' => $unavailable ? (int) config('telematics.backoff.base_seconds', 5) : null,
            'backoff_max_s' => $unavailable ? (int) config('telematics.backoff.max_seconds', 300) : null,
            'paused_until' => $pauseSeconds !== null ? $cursor->paused_until?->toIso8601String() : null,
            'backfill_requested' => $backfillRequested,
            'circuit_opened' => $e instanceof ProviderUnauthorized,
        ];
    }

    /**
     * The cursor is gone (expired or corrupt): start over without one — the
     * next cycle gets the last known state of every vehicle and a fresh
     * cursor — and refill the gap since the last point from history.
     */
    private function restartFromHistory(TelematicsFeedCursor $cursor): bool
    {
        $lastDataAt = $cursor->last_data_at;

        $cursor->forceFill(['end_cursor' => null, 'consecutive_failures' => 0]);

        if ($lastDataAt !== null) {
            BackfillVehicleStatsJob::dispatch(
                $this->integration,
                $this->feed,
                $lastDataAt->copy(),
                now(),
            );
        }

        return $lastDataAt !== null;
    }

    /**
     * A rejected token is not transient: stop polling this tenant until its
     * credentials are fixed. The integration goes to `error`, which takes it
     * out of the dispatcher's active set; re-testing the connection brings it
     * back to `active` and the feed resumes on its own. Other tenants are not
     * affected.
     */
    private function openCircuit(ProviderUnauthorized $e): void
    {
        $this->integration->update([
            'status' => TenantIntegrationStatus::Error,
            'last_error_at' => now(),
            'last_error_message' => SafeErrorMessage::from($e),
        ]);

        IntegrationStatusChanged::dispatch(
            $this->integration->team_id,
            $this->integration->id,
            (string) $this->integration->provider?->code,
            TenantIntegrationStatus::Error->value,
        );

        // Default channel: a state change of the integration, rare, and it
        // has to show in system.json. Never the provider's error text.
        SystemLog::degraded('telematics.circuit.opened', reason: 'unauthorized', input: [
            'team_id' => $this->integration->team_id,
            'integration_id' => $this->integration->id,
            'feed' => $this->feed->value,
        ], result: ['integration_status' => TenantIntegrationStatus::Error->value]);
    }

    private function backoffSeconds(int $failures): int
    {
        $base = (int) config('telematics.backoff.base_seconds', 5);
        $max = (int) config('telematics.backoff.max_seconds', 300);

        // $failures >= 1 (contador + 1), así que el exponente nunca es
        // negativo y la potencia es entera.
        return min($max, $base * (2 ** min(16, $failures - 1)));
    }

    private function publish(VehicleStatsIngestResult $result): void
    {
        $teamId = $this->integration->team_id;

        foreach (array_chunk(array_values($result->positions), self::BROADCAST_CHUNK) as $positions) {
            broadcast(new FleetPositionsUpdatedBroadcast($teamId, $positions));
        }

        $telemetry = [];

        foreach ($result->telemetry as $assetId => $readings) {
            $telemetry[] = ['asset_id' => $assetId, 'readings' => $readings];
        }

        foreach (array_chunk($telemetry, self::BROADCAST_CHUNK) as $chunk) {
            broadcast(new FleetTelemetryUpdatedBroadcast($teamId, $chunk));
        }
    }

    /**
     * Inline after-hours check over the assets whose newest point this cycle
     * is moving. The schedule is resolved once per cycle, and a tenant that
     * is open right now costs nothing more.
     *
     * @param  array{integration_id: int, feed: string}  $cycleInput
     */
    private function detectAfterHoursMovement(
        VehicleStatsIngestResult $result,
        TenantScheduleResolver $scheduleResolver,
        RaiseAfterHoursMovement $raiseAfterHours,
        array $cycleInput,
    ): void {
        $schedule = $scheduleResolver->resolve($this->integration->team_id);

        if (! $schedule->isPersisted) {
            SystemLog::skipped('assets.after_hours.evaluated', reason: 'no_schedule_profile', input: $cycleInput, debug: true, channel: 'telematics');

            return;
        }

        if ($schedule->withinOperatingHours) {
            SystemLog::skipped('assets.after_hours.evaluated', reason: 'within_operating_hours', input: $cycleInput, debug: true, channel: 'telematics');

            return;
        }

        $moving = array_filter(
            $result->positions,
            fn (array $position) => ($position['moving'] ?? false) === true
                && MovementCriterion::isMovingSpeed($position['speed_kph'] ?? null),
        );

        if ($moving === []) {
            SystemLog::skipped('assets.after_hours.evaluated', reason: 'no_moving_positions', input: $cycleInput, calc: ['positions_count' => count($result->positions)], debug: true, channel: 'telematics');

            return;
        }

        $assets = Asset::query()
            ->where('team_id', $this->integration->team_id)
            ->whereKey(array_keys($moving))
            ->get();

        foreach ($assets as $asset) {
            $position = $moving[$asset->id];

            $raiseAfterHours->execute(
                asset: $asset,
                schedule: $schedule,
                latitude: $position['latitude'],
                longitude: $position['longitude'],
                speedKph: $position['speed_kph'],
                recordedAt: Carbon::parse($position['recorded_at']),
            );
        }
    }

    private function later(?CarbonInterface $current, ?CarbonInterface $candidate): ?CarbonInterface
    {
        if ($candidate === null) {
            return $current;
        }

        return $current === null || $candidate->greaterThan($current) ? $candidate : $current;
    }
}
