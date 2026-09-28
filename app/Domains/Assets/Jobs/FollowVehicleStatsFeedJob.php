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
use Illuminate\Support\Facades\Log;

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

        if ($cursor->isPaused()) {
            return;
        }

        $startedAt = now();
        $cursor->forceFill(['last_polled_at' => $startedAt])->save();

        $result = new VehicleStatsIngestResult;
        $pages = 0;
        $failure = null;
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
            } while ($page->hasNextPage && $pages < $maxPages);
        } catch (ProviderRequestFailed $e) {
            $failure = $e;
            $this->handleFailure($cursor, $e);
        }

        if ($failure === null) {
            $cursor->forceFill([
                'consecutive_failures' => 0,
                'paused_until' => null,
                'last_error' => null,
                'last_success_at' => now(),
            ]);
        }

        $cursor->forceFill(['last_cycle_json' => [
            'duration_ms' => (int) $startedAt->diffInMilliseconds(now()),
            'pages' => $pages,
            'locations' => $result->locationsStored,
            'readings' => $result->readingsStored,
            'error' => $failure !== null ? class_basename($failure) : null,
        ]])->save();

        // Whatever pages committed before a failure are real data: publish
        // them and run the detectors over them all the same.
        $this->publish($result);

        if ($this->feed === TelematicsFeed::Motion && $result->positions !== []) {
            $this->detectAfterHoursMovement($result, $scheduleResolver, $raiseAfterHours);
        }

        Log::channel('telematics')->info('telematics.cycle', [
            'team_id' => $this->integration->team_id,
            'integration_id' => $this->integration->id,
            'feed' => $this->feed->value,
            'duration_ms' => $cursor->last_cycle_json['duration_ms'],
            'pages' => $pages,
            'locations' => $result->locationsStored,
            'readings' => $result->readingsStored,
            'moved_assets' => count($result->positions),
            'lag_s' => $cursor->lagSeconds(),
            'error' => $failure?->getMessage(),
        ]);
    }

    public function uniqueId(): string
    {
        return "telematics-feed:{$this->integration->id}:{$this->feed->value}";
    }

    private function handleFailure(TelematicsFeedCursor $cursor, ProviderRequestFailed $e): void
    {
        $failures = $cursor->consecutive_failures + 1;

        $cursor->forceFill([
            'consecutive_failures' => $failures,
            'last_error' => $e->getMessage(),
        ]);

        match (true) {
            $e instanceof ProviderRateLimited => $cursor->forceFill([
                // Whole seconds, rounded up: the column has no fractions, and
                // truncating would resume before the provider allows.
                'paused_until' => now()->addSeconds((int) ceil(max(1.0, $e->retryAfterSeconds))),
            ]),
            $e instanceof ProviderUnavailable => $cursor->forceFill([
                'paused_until' => now()->addSeconds($this->backoffSeconds($failures)),
            ]),
            $e instanceof ProviderCursorRejected => $this->restartFromHistory($cursor),
            $e instanceof ProviderUnauthorized => $this->openCircuit($e),
            default => null,
        };
    }

    /**
     * The cursor is gone (expired or corrupt): start over without one — the
     * next cycle gets the last known state of every vehicle and a fresh
     * cursor — and refill the gap since the last point from history.
     */
    private function restartFromHistory(TelematicsFeedCursor $cursor): void
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
            'last_error_message' => $e->getMessage(),
        ]);

        IntegrationStatusChanged::dispatch(
            $this->integration->team_id,
            $this->integration->id,
            (string) $this->integration->provider?->code,
            TenantIntegrationStatus::Error->value,
        );
    }

    private function backoffSeconds(int $failures): int
    {
        $base = (int) config('telematics.backoff.base_seconds', 5);
        $max = (int) config('telematics.backoff.max_seconds', 300);

        return (int) min($max, $base * (2 ** min(16, $failures - 1)));
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
     */
    private function detectAfterHoursMovement(
        VehicleStatsIngestResult $result,
        TenantScheduleResolver $scheduleResolver,
        RaiseAfterHoursMovement $raiseAfterHours,
    ): void {
        $schedule = $scheduleResolver->resolve($this->integration->team_id);

        if (! $schedule->isPersisted || $schedule->withinOperatingHours) {
            return;
        }

        $moving = array_filter(
            $result->positions,
            fn (array $position) => ($position['moving'] ?? false) === true
                && MovementCriterion::isMovingSpeed(isset($position['speed_kph']) ? (float) $position['speed_kph'] : null),
        );

        if ($moving === []) {
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
