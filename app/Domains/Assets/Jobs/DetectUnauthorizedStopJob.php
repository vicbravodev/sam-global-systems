<?php

namespace App\Domains\Assets\Jobs;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Context\Actions\ResolveGeofenceContext;
use App\Domains\Context\Enums\GeofenceMatchType;
use App\Domains\Context\Models\Geofence;
use App\Domains\Ingestion\Actions\QueueRawEventForProcessing;
use App\Domains\Ingestion\Actions\StoreRawEvent;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Models\RawEvent;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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

        foreach ($candidates as $asset) {
            $this->inspectAsset($asset, $teamId, $resolveGeofences, $storeRawEvent, $queueForProcessing);
        }
    }

    private function inspectAsset(
        Asset $asset,
        int $teamId,
        ResolveGeofenceContext $resolveGeofences,
        StoreRawEvent $storeRawEvent,
        QueueRawEventForProcessing $queueForProcessing,
    ): void {
        $insideKnownGeofence = collect($resolveGeofences->execute(
            (float) $asset->last_latitude,
            (float) $asset->last_longitude,
            $teamId,
        ))->contains(function (array $match) {
            $type = $match['match_type'] ?? null;

            return ($type instanceof GeofenceMatchType ? $type : GeofenceMatchType::tryFrom((string) $type)) === GeofenceMatchType::Inside;
        });

        // Inside a known place: not suspicious, but checked again next minute
        // in case the unit is towed out while "stopped".
        if ($insideKnownGeofence) {
            return;
        }

        $anchor = $asset->last_moving_at;
        $deduplicationKey = sprintf('suspicious_stop:%d:%d', $asset->id, $anchor->getTimestamp());

        $alreadyRaised = RawEvent::query()
            ->where('team_id', $teamId)
            ->where('deduplication_key', $deduplicationKey)
            ->exists();

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
                    'stopped_minutes' => (int) $anchor->diffInMinutes(now()),
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

        // The episode is handled; drop it from the next sweeps' candidates.
        $asset->forceFill(['stop_alerted_for' => $anchor])->save();
    }
}
