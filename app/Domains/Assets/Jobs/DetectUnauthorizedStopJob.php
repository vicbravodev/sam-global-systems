<?php

namespace App\Domains\Assets\Jobs;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Context\Actions\ResolveGeofenceContext;
use App\Domains\Context\Enums\GeofenceMatchType;
use App\Domains\Context\Models\Geofence;
use App\Domains\Context\Support\HaversineDistance;
use App\Domains\Ingestion\Actions\QueueRawEventForProcessing;
use App\Domains\Ingestion\Actions\StoreRawEvent;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Models\RawEvent;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Unauthorized-stop detector (Roadmap V2-C3): a unit standing still beyond
 * `monitoring.stop_alert_minutes` OUTSIDE every known geofence raises an
 * internal `suspicious_stop` event — in the Mexican monitoring context a
 * prolonged stop in the middle of nowhere precedes cargo theft.
 *
 * A stop is the absence of movement, so it cannot be caught by an event
 * alone. The telematics feed keeps the motion state on each asset
 * (`last_moving_at`, `stopped_since`) as points arrive; this sweep runs every
 * minute and only reads the few assets whose stop has crossed the tenant's
 * threshold — one indexed query per tenant, no history scan — so the alert
 * fires within a minute of the threshold.
 *
 * Guard rails against noise: requires the tenant to have configured at least
 * one active geofence (otherwise every stop is "outside"), `0` disables it, a
 * stop with no movement in the last 24 h is long-term parking, and the device
 * must still be reporting. Each episode is anchored to its last moving point
 * (`suspicious_stop:{asset}:{anchor ts}`) and alerts at most once.
 */
class DetectUnauthorizedStopJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const string SETTING_KEY = 'monitoring.stop_alert_minutes';

    public const int DEFAULT_STOP_MINUTES = 10;

    public const string EVENT_TYPE_CODE = 'suspicious_stop';

    /** Without a position or heartbeat this recent, the device may be dark, not stopped. */
    public const int FRESHNESS_MINUTES = 15;

    /** A stop with no movement in this window is long-term parking, not suspicious. */
    public const int MAX_ANCHOR_HOURS = 24;

    public function __construct()
    {
        $this->onQueue('ingestion');
    }

    public function handle(
        TenantConfigResolver $tenantConfig,
        ResolveGeofenceContext $resolveGeofences,
        StoreRawEvent $storeRawEvent,
        QueueRawEventForProcessing $queueForProcessing,
    ): void {
        // Vigilancia de plataforma: recorre los tenants a propósito, pero
        // inspecciona cada uno dentro de su propio contexto. Ver §2.1.
        $teamIds = TenantContext::withoutTenant(fn () => Geofence::query()
            ->where('is_active', true)
            ->whereNotNull('team_id')
            ->distinct()
            ->pluck('team_id')
            ->map(fn ($teamId) => (int) $teamId)
            ->all());

        foreach ($teamIds as $teamId) {
            TenantContext::for($teamId, fn () => $this->sweepTeam(
                $teamId,
                $tenantConfig,
                $resolveGeofences,
                $storeRawEvent,
                $queueForProcessing,
            ));
        }
    }

    private function sweepTeam(
        int $teamId,
        TenantConfigResolver $tenantConfig,
        ResolveGeofenceContext $resolveGeofences,
        StoreRawEvent $storeRawEvent,
        QueueRawEventForProcessing $queueForProcessing,
    ): void {
        $stopMinutes = (int) $tenantConfig->resolve($teamId, self::SETTING_KEY, self::DEFAULT_STOP_MINUTES);

        if ($stopMinutes <= 0) {
            SystemLog::skipped('assets.unauthorized_stop_sweep.completed', reason: 'disabled', input: ['team_id' => $teamId], calc: ['stop_minutes' => $stopMinutes]);

            return;
        }

        $fresh = now()->subMinutes(self::FRESHNESS_MINUTES);

        $candidates = Asset::query()
            ->where('team_id', $teamId)
            ->monitored()
            ->whereNotIn('status', [AssetStatus::Inactive, AssetStatus::Maintenance])
            ->whereNotNull('stopped_since')
            ->whereNotNull('last_latitude')
            ->where('last_moving_at', '<=', now()->subMinutes($stopMinutes))
            ->where('last_moving_at', '>=', now()->subHours(self::MAX_ANCHOR_HOURS))
            ->where(fn ($query) => $query
                ->whereNull('stop_alerted_for')
                ->orWhereColumn('stop_alerted_for', '!=', 'last_moving_at'))
            ->where(fn ($query) => $query
                ->where('last_location_at', '>=', $fresh)
                ->orWhere('device_last_connected_at', '>=', $fresh))
            ->get();

        $counts = ['inside_geofence' => 0, 'same_place' => 0, 'raised' => 0, 'already_raised' => 0];

        if ($candidates->isEmpty()) {
            // Sin candidatos no se cargan las geocercas (`geofences_count` null).
            $this->logSweep($teamId, $stopMinutes, null, $candidates->count(), $counts);

            return;
        }

        // One cache read + unserialize of the tenant's geofences per sweep,
        // not one per stopped unit (a parked fleet at a depot is every unit).
        $geofences = $resolveGeofences->activeGeofences($teamId);

        foreach ($candidates as $asset) {
            $counts[$this->inspectAsset($asset, $teamId, $stopMinutes, $geofences, $resolveGeofences, $storeRawEvent, $queueForProcessing)]++;
        }

        $this->logSweep($teamId, $stopMinutes, $geofences->count(), $candidates->count(), $counts);
    }

    /**
     * Resumen del barrido de un tenant (ya dentro de su contexto). En `debug`
     * cuando no hubo candidatos: es lo normal en cada minuto.
     *
     * @param  array{inside_geofence: int, same_place: int, raised: int, already_raised: int}  $counts
     */
    private function logSweep(int $teamId, int $stopMinutes, ?int $geofencesCount, int $candidatesCount, array $counts): void
    {
        SystemLog::ok('assets.unauthorized_stop_sweep.completed', input: ['team_id' => $teamId], calc: [
            'stop_minutes' => $stopMinutes,
            'freshness_minutes' => self::FRESHNESS_MINUTES,
            'max_anchor_hours' => self::MAX_ANCHOR_HOURS,
            'realert_hours' => (int) config('telematics.stop_realert_hours', 6),
            'realert_radius_m' => (float) config('telematics.stop_realert_radius_m', 200),
            'geofences_count' => $geofencesCount,
        ], result: [
            'candidates_count' => $candidatesCount,
            'inside_geofence_count' => $counts['inside_geofence'],
            'same_place_count' => $counts['same_place'],
            'raised_count' => $counts['raised'],
            'already_raised_count' => $counts['already_raised'],
        ], debug: $candidatesCount === 0);
    }

    /**
     * @param  Collection<int, Geofence>  $geofences  the tenant's active geofences
     * @return 'inside_geofence'|'same_place'|'raised'|'already_raised'
     */
    private function inspectAsset(
        Asset $asset,
        int $teamId,
        int $stopMinutes,
        Collection $geofences,
        ResolveGeofenceContext $resolveGeofences,
        StoreRawEvent $storeRawEvent,
        QueueRawEventForProcessing $queueForProcessing,
    ): string {
        $insideKnownGeofence = collect($resolveGeofences->matchAgainst(
            $geofences,
            (float) $asset->last_latitude,
            (float) $asset->last_longitude,
        ))->contains(function (array $match) {
            $type = $match['match_type'] ?? null;

            return ($type instanceof GeofenceMatchType ? $type : GeofenceMatchType::tryFrom((string) $type)) === GeofenceMatchType::Inside;
        });

        // Inside a known place: not suspicious, but checked again next minute
        // in case the unit is towed out while "stopped".
        if ($insideKnownGeofence) {
            return 'inside_geofence';
        }

        $anchor = $asset->last_moving_at;

        if ($this->isSamePlaceAsLastAlert($asset)) {
            // A unit shuffling around the place it was already alerted at:
            // the operator knows. Handle the episode without a new alert.
            $asset->forceFill(['stop_alerted_for' => $anchor])->save();

            return 'same_place';
        }

        $deduplicationKey = sprintf('suspicious_stop:%d:%d', $asset->id, $anchor->getTimestamp());

        $alreadyRaised = RawEvent::query()
            ->where('team_id', $teamId)
            ->where('deduplication_key', $deduplicationKey)
            ->exists();

        $rawEvent = null;
        $stoppedMinutes = (int) $anchor->diffInMinutes(now());

        if (! $alreadyRaised) {
            $rawEvent = $storeRawEvent->execute(
                payload: [
                    'eventType' => self::EVENT_TYPE_CODE,
                    'time' => now()->toIso8601String(),
                    'internal' => [
                        'monitor' => 'unauthorized_stop_watchdog',
                        'asset_id' => $asset->id,
                    ],
                    'asset_name' => $asset->name,
                    'asset_code' => $asset->code,
                    'stopped_minutes' => $stoppedMinutes,
                    'last_moving_at' => $anchor->toIso8601String(),
                    'location' => [
                        'latitude' => (float) $asset->last_latitude,
                        'longitude' => (float) $asset->last_longitude,
                    ],
                ],
                sourceType: EventSourceType::InternalMonitor->value,
                teamId: $teamId,
                providerId: null,
                deduplicationKey: $deduplicationKey,
                eventTypeRaw: self::EVENT_TYPE_CODE,
            );

            $queueForProcessing->execute($rawEvent);
        }

        // The episode is handled; drop it from the next sweeps' candidates,
        // and remember where, so a stop at the same place is not re-alerted.
        $asset->forceFill([
            'stop_alerted_for' => $anchor,
            'stop_alerted_latitude' => $asset->stop_latitude ?? $asset->last_latitude,
            'stop_alerted_longitude' => $asset->stop_longitude ?? $asset->last_longitude,
        ])->save();

        if ($rawEvent === null) {
            return 'already_raised';
        }

        // La distancia al último aviso no se registra: sale de coordenadas.
        SystemLog::ok('assets.unauthorized_stop.raised', input: [
            'team_id' => $teamId,
            'asset_id' => $asset->id,
        ], calc: [
            'stopped_minutes' => $stoppedMinutes,
            'stop_minutes' => $stopMinutes,
        ], result: [
            'raw_event_id' => $rawEvent->id,
            'job_requested' => true,
        ]);

        return 'raised';
    }

    private function isSamePlaceAsLastAlert(Asset $asset): bool
    {
        if (
            $asset->stop_alerted_for === null
            || $asset->stop_alerted_latitude === null
            || $asset->stop_alerted_longitude === null
            || $asset->stop_alerted_for->lt(now()->subHours((int) config('telematics.stop_realert_hours', 6)))
        ) {
            return false;
        }

        return HaversineDistance::meters(
            $asset->stop_alerted_latitude,
            $asset->stop_alerted_longitude,
            (float) ($asset->stop_latitude ?? $asset->last_latitude),
            (float) ($asset->stop_longitude ?? $asset->last_longitude),
        ) <= (float) config('telematics.stop_realert_radius_m', 200);
    }
}
