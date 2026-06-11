<?php

namespace App\Domains\Assets\Jobs;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Context\Actions\ResolveGeofenceContext;
use App\Domains\Context\Enums\GeofenceMatchType;
use App\Domains\Context\Models\Geofence;
use App\Domains\Ingestion\Actions\QueueRawEventForProcessing;
use App\Domains\Ingestion\Actions\StoreRawEvent;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Models\RawEvent;
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
 * Guard rails against noise: requires the tenant to have configured at least
 * one active geofence (otherwise every stop is "outside" and the detector
 * stays silent), `0` disables it, and the episode is anchored to the last
 * moving position (`suspicious_stop:{asset}:{anchor ts}`) so each stop
 * alerts at most once.
 */
class DetectUnauthorizedStopJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const string SETTING_KEY = 'monitoring.stop_alert_minutes';

    public const int DEFAULT_STOP_MINUTES = 10;

    public const string EVENT_TYPE_CODE = 'suspicious_stop';

    /** Speed (km/h) above which a snapshot counts as moving. */
    public const float MOVING_SPEED_KPH = 1.0;

    /** Positions older than this are not evidence of a current stop. */
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
        /** @var array<int, bool> $teamHasGeofences */
        $teamHasGeofences = [];

        Asset::withoutGlobalScopes()
            ->whereNotNull('team_id')
            ->whereNotIn('status', [AssetStatus::Inactive, AssetStatus::Maintenance])
            ->with('latestLocation')
            ->chunkById(200, function ($assets) use (&$teamHasGeofences, $tenantConfig, $resolveGeofences, $storeRawEvent, $queueForProcessing) {
                foreach ($assets as $asset) {
                    $this->inspectAsset($asset, $teamHasGeofences, $tenantConfig, $resolveGeofences, $storeRawEvent, $queueForProcessing);
                }
            });
    }

    /**
     * @param  array<int, bool>  $teamHasGeofences
     */
    private function inspectAsset(
        Asset $asset,
        array &$teamHasGeofences,
        TenantConfigResolver $tenantConfig,
        ResolveGeofenceContext $resolveGeofences,
        StoreRawEvent $storeRawEvent,
        QueueRawEventForProcessing $queueForProcessing,
    ): void {
        $teamId = (int) $asset->team_id;

        $stopMinutes = (int) $tenantConfig->resolve($teamId, self::SETTING_KEY, self::DEFAULT_STOP_MINUTES);

        if ($stopMinutes <= 0) {
            return;
        }

        $location = $asset->latestLocation;

        if (
            $location === null
            || $location->latitude === null
            || $location->recorded_at === null
            || $location->recorded_at->lt(now()->subMinutes(self::FRESHNESS_MINUTES))
            || ($location->speed !== null && (float) $location->speed > self::MOVING_SPEED_KPH)
        ) {
            return;
        }

        // Episode anchor: the last position where the unit was still moving.
        $anchor = AssetLocationSnapshot::query()
            ->where('asset_id', $asset->id)
            ->where('speed', '>', self::MOVING_SPEED_KPH)
            ->where('recorded_at', '>=', now()->subHours(self::MAX_ANCHOR_HOURS))
            ->orderByDesc('recorded_at')
            ->first();

        if ($anchor === null || $anchor->recorded_at->gt(now()->subMinutes($stopMinutes))) {
            return;
        }

        $teamHasGeofences[$teamId] ??= Geofence::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->exists();

        if (! $teamHasGeofences[$teamId]) {
            return;
        }

        $insideKnownGeofence = collect($resolveGeofences->execute(
            (float) $location->latitude,
            (float) $location->longitude,
            $teamId,
        ))->contains(function (array $match) {
            $type = $match['match_type'] ?? null;

            return ($type instanceof GeofenceMatchType ? $type : GeofenceMatchType::tryFrom((string) $type)) === GeofenceMatchType::Inside;
        });

        if ($insideKnownGeofence) {
            return;
        }

        $deduplicationKey = sprintf('suspicious_stop:%d:%d', $asset->id, $anchor->recorded_at->getTimestamp());

        $alreadyRaised = RawEvent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('deduplication_key', $deduplicationKey)
            ->exists();

        if ($alreadyRaised) {
            return;
        }

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
                'stopped_minutes' => (int) $anchor->recorded_at->diffInMinutes(now()),
                'last_moving_at' => $anchor->recorded_at->toIso8601String(),
                'location' => [
                    'latitude' => (float) $location->latitude,
                    'longitude' => (float) $location->longitude,
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
}
