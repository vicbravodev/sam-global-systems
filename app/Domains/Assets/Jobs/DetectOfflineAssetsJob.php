<?php

namespace App\Domains\Assets\Jobs;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Incidents\Jobs\ApplyExternalResolutionJob;
use App\Domains\Ingestion\Actions\QueueRawEventForProcessing;
use App\Domains\Ingestion\Actions\StoreRawEvent;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Offline-asset watchdog (Roadmap V2-C1): an asset that stops reporting for
 * longer than its threshold raises a `device_offline` internal event that
 * runs the FULL pipeline (normalization → context → AI → decision →
 * incident/notification) — silence can be theft, jamming or a yanked device.
 *
 * Anti-spam: the episode is keyed by the asset's frozen `last_seen_at`
 * (`offline:{asset}:{ts}`) — one event per silence episode, however many
 * times the scheduler ticks. When the asset reports again the episode's
 * event is marked externally resolved.
 *
 * Threshold: per-asset `metadata_json.offline_alert_minutes`, else the
 * tenant's `monitoring.offline_alert_minutes` (default 15). `0` disables the
 * watchdog for that asset/tenant.
 */
class DetectOfflineAssetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const string SETTING_KEY = 'monitoring.offline_alert_minutes';

    public const int DEFAULT_OFFLINE_MINUTES = 15;

    public const string EVENT_TYPE_CODE = 'device_offline';

    public function __construct()
    {
        $this->onQueue('ingestion');
    }

    public function handle(
        TenantConfigResolver $tenantConfig,
        StoreRawEvent $storeRawEvent,
        QueueRawEventForProcessing $queueForProcessing,
    ): void {
        $this->detectSilentAssets($tenantConfig, $storeRawEvent, $queueForProcessing);
        $this->resolveRecoveredEpisodes();
    }

    private function detectSilentAssets(
        TenantConfigResolver $tenantConfig,
        StoreRawEvent $storeRawEvent,
        QueueRawEventForProcessing $queueForProcessing,
    ): void {
        Asset::withoutGlobalScopes()
            ->whereNotNull('team_id')
            ->whereNotNull('last_seen_at')
            ->whereNotIn('status', [AssetStatus::Inactive, AssetStatus::Maintenance])
            ->with('latestLocation')
            ->chunkById(200, function ($assets) use ($tenantConfig, $storeRawEvent, $queueForProcessing) {
                foreach ($assets as $asset) {
                    $this->inspectAsset($asset, $tenantConfig, $storeRawEvent, $queueForProcessing);
                }
            });
    }

    private function inspectAsset(
        Asset $asset,
        TenantConfigResolver $tenantConfig,
        StoreRawEvent $storeRawEvent,
        QueueRawEventForProcessing $queueForProcessing,
    ): void {
        $threshold = $this->thresholdMinutesFor($asset, $tenantConfig);

        if ($threshold <= 0 || $asset->last_seen_at->gt(now()->subMinutes($threshold))) {
            return;
        }

        $deduplicationKey = sprintf('offline:%d:%d', $asset->id, $asset->last_seen_at->getTimestamp());

        $alreadyRaised = RawEvent::withoutGlobalScopes()
            ->where('team_id', $asset->team_id)
            ->where('deduplication_key', $deduplicationKey)
            ->exists();

        if ($alreadyRaised) {
            return;
        }

        $location = $asset->latestLocation;
        $wasInMotion = $location?->speed !== null && (float) $location->speed > 0.1;

        $rawEvent = $storeRawEvent->execute(
            payload: [
                'eventType' => self::EVENT_TYPE_CODE,
                'time' => now()->toIso8601String(),
                'internal' => [
                    'monitor' => 'offline_watchdog',
                    'asset_id' => $asset->id,
                ],
                'asset_name' => $asset->name,
                'asset_code' => $asset->code,
                'last_seen_at' => $asset->last_seen_at->toIso8601String(),
                'silent_minutes' => (int) $asset->last_seen_at->diffInMinutes(now()),
                'threshold_minutes' => $threshold,
                // Last known position so geofence context still works, plus
                // the motion flag — going silent while moving smells like
                // jamming or a yanked device, not a parked unit.
                'location' => $location !== null && $location->latitude !== null ? [
                    'latitude' => (float) $location->latitude,
                    'longitude' => (float) $location->longitude,
                ] : null,
                'was_in_motion' => $wasInMotion,
            ],
            sourceType: EventSourceType::InternalMonitor->value,
            teamId: (int) $asset->team_id,
            providerId: null,
            deduplicationKey: $deduplicationKey,
            eventTypeRaw: self::EVENT_TYPE_CODE,
        );

        $queueForProcessing->execute($rawEvent);
    }

    private function thresholdMinutesFor(Asset $asset, TenantConfigResolver $tenantConfig): int
    {
        $override = ($asset->metadata_json ?? [])['offline_alert_minutes'] ?? null;

        if (is_numeric($override)) {
            return (int) $override;
        }

        return (int) $tenantConfig->resolve(
            (int) $asset->team_id,
            self::SETTING_KEY,
            self::DEFAULT_OFFLINE_MINUTES,
        );
    }

    /**
     * An asset that reported again closes its offline episode: the episode's
     * normalized event is marked resolved at the source and the standard
     * external-resolution flow annotates (or closes, per tenant setting) the
     * incident it opened.
     */
    private function resolveRecoveredEpisodes(): void
    {
        $eventTypeId = EventType::query()->where('code', self::EVENT_TYPE_CODE)->value('id');

        if ($eventTypeId === null) {
            return;
        }

        NormalizedEvent::withoutGlobalScopes()
            ->where('event_type_id', $eventTypeId)
            ->where('occurred_at', '>=', now()->subDays(7))
            ->whereNotNull('asset_id')
            ->with('asset')
            ->chunkById(200, function ($events) {
                foreach ($events as $event) {
                    if (($event->payload_normalized_json['is_resolved'] ?? null) === true) {
                        continue;
                    }

                    $lastSeen = $event->asset?->last_seen_at;

                    if ($lastSeen === null || $event->occurred_at === null || ! $lastSeen->gt($event->occurred_at)) {
                        continue;
                    }

                    $payload = $event->payload_normalized_json ?? [];
                    $payload['is_resolved'] = true;
                    $payload['external_resolved_at'] = $lastSeen->toIso8601String();

                    $event->forceFill(['payload_normalized_json' => $payload])->save();

                    ApplyExternalResolutionJob::dispatch((int) $event->id);
                }
            });
    }
}
