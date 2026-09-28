<?php

namespace App\Domains\Assets\Jobs;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Support\MovementCriterion;
use App\Domains\Incidents\Jobs\ApplyExternalResolutionJob;
use App\Domains\Ingestion\Actions\QueueRawEventForProcessing;
use App\Domains\Ingestion\Actions\StoreRawEvent;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Offline-asset watchdog (Roadmap V2-C1): an asset whose telematics device
 * stops connecting for longer than its threshold raises a `device_offline`
 * internal event that runs the ingestion pipeline (normalization → context)
 * and feeds panic/jamming correlation — silence can be theft, jamming or a
 * yanked device. Its `maintenance` category is excluded from AI evaluation
 * (see `config('ai.skip_evaluation_categories')`).
 *
 * Liveness signal: the device heartbeat (`device_last_connected_at`, polled by
 * {@see PollAssetConnectivityJob} from Samsara `GET /gateways`), NOT the last
 * GPS fix. A parked vehicle only produces a GPS fix about once an hour while
 * its gateway stays connected, so watching `last_seen_at` raised one false
 * `device_offline` per parked vehicle per hour (~7.5 per asset per day).
 *
 * Noise guards, each one closing a measured source of false positives:
 * - Assets without a connectivity reading (unpaired or deactivated gateways,
 *   providers without a connectivity feed) are not watched.
 * - A reading older than {@see CONNECTIVITY_FRESHNESS_MINUTES} is ignored: if
 *   WE stopped hearing from the provider, that is an integration outage, not
 *   a fleet-wide device outage.
 * - A silence that started more than {@see MAX_EPISODE_AGE_HOURS} ago is never
 *   raised late: the watchdog ticks every 5 minutes, so an episode that old
 *   is backlog (watchdog/scheduler down, new integration, chronically dead
 *   device), not news.
 * - Two thresholds: a device that drops while the vehicle was moving is the
 *   security signal (`monitoring.offline_alert_minutes`, default 15); a
 *   parked vehicle's gateway may legitimately sleep, so it gets a longer
 *   grace (`monitoring.offline_parked_alert_minutes`, default 180).
 *
 * Anti-spam: the episode is keyed by the frozen heartbeat
 * (`offline:{asset}:{ts}`) — one event per silence episode, however many
 * times the scheduler ticks. When the device connects again the episode's
 * event is marked externally resolved.
 *
 * Threshold overrides: per-asset `metadata_json.offline_alert_minutes`
 * replaces the in-motion threshold; `0` (per asset or tenant-wide in-motion
 * setting) disables the watchdog, and a parked setting of `0` disables only
 * parked alerts.
 */
class DetectOfflineAssetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const string SETTING_KEY = 'monitoring.offline_alert_minutes';

    public const string PARKED_SETTING_KEY = 'monitoring.offline_parked_alert_minutes';

    public const int DEFAULT_OFFLINE_MINUTES = 15;

    public const int DEFAULT_PARKED_OFFLINE_MINUTES = 180;

    public const int CONNECTIVITY_FRESHNESS_MINUTES = 15;

    public const int MAX_EPISODE_AGE_HOURS = 24;

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
        // Vigilancia de plataforma: recorre todos los tenants a propósito,
        // pero inspecciona cada activo dentro del contexto del suyo. Ver §2.1.
        TenantContext::withoutTenant(fn () => Asset::query()
            ->whereNotNull('team_id')
            ->monitored()
            ->whereNotIn('status', [AssetStatus::Inactive, AssetStatus::Maintenance])
            ->where('device_connectivity_polled_at', '>=', now()->subMinutes(self::CONNECTIVITY_FRESHNESS_MINUTES))
            ->where('device_last_connected_at', '>=', now()->subHours(self::MAX_EPISODE_AGE_HOURS))
            ->where('device_last_connected_at', '<', now())
            ->with('latestLocation')
            ->chunkById(200, function ($assets) use ($tenantConfig, $storeRawEvent, $queueForProcessing) {
                foreach ($assets as $asset) {
                    TenantContext::for($asset->team_id, fn () => $this->inspectAsset($asset, $tenantConfig, $storeRawEvent, $queueForProcessing));
                }
            }));
    }

    private function inspectAsset(
        Asset $asset,
        TenantConfigResolver $tenantConfig,
        StoreRawEvent $storeRawEvent,
        QueueRawEventForProcessing $queueForProcessing,
    ): void {
        $lastConnectedAt = $asset->device_last_connected_at;
        $location = $asset->latestLocation;

        // Moving at the last fix taken around the moment the device dropped:
        // going silent mid-trip smells like jamming or a yanked device. An
        // old fix says nothing about the vehicle's state when it dropped.
        // Criterio único de movimiento (MovementCriterion): una velocidad
        // fantasma de 0.5 km/h de un tracto estacionado no baja el umbral.
        $wasInMotion = $location?->speed !== null
            && MovementCriterion::isMoving($asset, (float) $location->speed)
            && $location->recorded_at !== null
            && $location->recorded_at->gte($lastConnectedAt->copy()->subMinutes(self::CONNECTIVITY_FRESHNESS_MINUTES));

        $threshold = $this->thresholdMinutesFor($asset, $tenantConfig, $wasInMotion);

        if ($threshold <= 0 || $lastConnectedAt->gt(now()->subMinutes($threshold))) {
            return;
        }

        $deduplicationKey = sprintf('offline:%d:%d', $asset->id, $lastConnectedAt->getTimestamp());

        $alreadyRaised = RawEvent::query()
            ->where('team_id', $asset->team_id)
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
                    'monitor' => 'offline_watchdog',
                    'asset_id' => $asset->id,
                ],
                'asset_name' => $asset->name,
                'asset_code' => $asset->code,
                'last_connected_at' => $lastConnectedAt->toIso8601String(),
                'device_health_status' => $asset->device_health_status,
                'last_seen_at' => $asset->last_seen_at?->toIso8601String(),
                'silent_minutes' => (int) $lastConnectedAt->diffInMinutes(now()),
                'threshold_minutes' => $threshold,
                // Last known position so geofence context still works.
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

    private function thresholdMinutesFor(Asset $asset, TenantConfigResolver $tenantConfig, bool $wasInMotion): int
    {
        $override = ($asset->metadata_json ?? [])['offline_alert_minutes'] ?? null;

        $inMotion = is_numeric($override)
            ? (int) $override
            : (int) $tenantConfig->resolve((int) $asset->team_id, self::SETTING_KEY, self::DEFAULT_OFFLINE_MINUTES);

        if ($inMotion <= 0 || $wasInMotion) {
            return $inMotion;
        }

        $parked = (int) $tenantConfig->resolve(
            (int) $asset->team_id,
            self::PARKED_SETTING_KEY,
            self::DEFAULT_PARKED_OFFLINE_MINUTES,
        );

        // Parked grace never undercuts the in-motion threshold.
        return $parked <= 0 ? 0 : max($parked, $inMotion);
    }

    /**
     * A device that connected again (or an asset that reported a newer GPS
     * fix — also proof of life, and the only signal for episodes raised
     * before the connectivity feed existed) closes its offline episode: the episode's
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

        NormalizedEvent::query()
            ->where('event_type_id', $eventTypeId)
            ->where('occurred_at', '>=', now()->subDays(7))
            ->whereNotNull('asset_id')
            ->with('asset')
            ->chunkById(200, function ($events) {
                foreach ($events as $event) {
                    if (($event->payload_normalized_json['is_resolved'] ?? null) === true) {
                        continue;
                    }

                    $lastSeen = $this->lastProofOfLife($event->asset);

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

    private function lastProofOfLife(?Asset $asset): ?CarbonInterface
    {
        $candidates = array_filter([$asset?->device_last_connected_at, $asset?->last_seen_at]);

        if ($candidates === []) {
            return null;
        }

        return array_reduce($candidates, fn (?CarbonInterface $carry, CarbonInterface $time) => $carry === null || $time->gt($carry) ? $time : $carry);
    }
}
