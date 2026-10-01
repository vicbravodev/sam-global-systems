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
use App\Support\SystemLog;
use App\Support\TenantContext;
use Carbon\Carbon;
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
        $counts = $this->detectSilentAssets($tenantConfig, $storeRawEvent, $queueForProcessing);
        $resolved = $this->resolveRecoveredEpisodes();

        // Recorrido de plataforma: sólo conteos, nunca ids de un tenant. Los
        // umbrales efectivos son por tenant y van en cada `assets.offline.raised`.
        SystemLog::ok('assets.offline_sweep.completed', calc: [
            'connectivity_freshness_minutes' => self::CONNECTIVITY_FRESHNESS_MINUTES,
            'max_episode_age_hours' => self::MAX_EPISODE_AGE_HOURS,
            'default_moving_threshold_minutes' => self::DEFAULT_OFFLINE_MINUTES,
            'default_parked_threshold_minutes' => self::DEFAULT_PARKED_OFFLINE_MINUTES,
        ], result: [
            'scanned_count' => $counts['scanned'],
            'raised_count' => $counts['raised'],
            'already_raised_count' => $counts['already_raised'],
            'within_threshold_count' => $counts['within_threshold'],
            'disabled_count' => $counts['disabled'],
            'in_motion_count' => $counts['in_motion'],
            'resolved_count' => $resolved,
        ]);
    }

    /**
     * @return array{scanned: int, raised: int, already_raised: int, within_threshold: int, disabled: int, in_motion: int}
     */
    private function detectSilentAssets(
        TenantConfigResolver $tenantConfig,
        StoreRawEvent $storeRawEvent,
        QueueRawEventForProcessing $queueForProcessing,
    ): array {
        $counts = ['scanned' => 0, 'raised' => 0, 'already_raised' => 0, 'within_threshold' => 0, 'disabled' => 0, 'in_motion' => 0];

        // Vigilancia de plataforma: recorre todos los tenants a propósito,
        // pero inspecciona cada activo dentro del contexto del suyo. Ver §2.1.
        TenantContext::withoutTenant(function () use ($tenantConfig, $storeRawEvent, $queueForProcessing, &$counts): void {
            Asset::query()
                ->whereNotNull('team_id')
                ->monitored()
                ->whereNotIn('status', [AssetStatus::Inactive, AssetStatus::Maintenance])
                ->where('device_connectivity_polled_at', '>=', now()->subMinutes(self::CONNECTIVITY_FRESHNESS_MINUTES))
                ->where('device_last_connected_at', '>=', now()->subHours(self::MAX_EPISODE_AGE_HOURS))
                ->where('device_last_connected_at', '<', now())
                ->with('latestLocation')
                ->chunkById(200, function ($assets) use ($tenantConfig, $storeRawEvent, $queueForProcessing, &$counts) {
                    foreach ($assets as $asset) {
                        // El where de arriba ya excluye latidos nulos; el
                        // narrowing deja ese contrato explícito en el tipo.
                        $lastConnectedAt = $asset->device_last_connected_at;

                        if ($lastConnectedAt === null) {
                            continue;
                        }

                        $wasInMotion = $this->wasInMotion($asset, $lastConnectedAt);
                        $outcome = TenantContext::for($asset->team_id, fn () => $this->inspectAsset($asset, $lastConnectedAt, $wasInMotion, $tenantConfig, $storeRawEvent, $queueForProcessing));

                        $counts['scanned']++;
                        $counts[$outcome]++;

                        if ($wasInMotion) {
                            $counts['in_motion']++;
                        }
                    }
                });
        });

        return $counts;
    }

    /**
     * Moving at the last fix taken around the moment the device dropped:
     * going silent mid-trip smells like jamming or a yanked device. An old
     * fix says nothing about the vehicle's state when it dropped. Criterio
     * único de movimiento (MovementCriterion): una velocidad fantasma de
     * 0.5 km/h de un tracto estacionado no baja el umbral.
     */
    private function wasInMotion(Asset $asset, CarbonInterface $lastConnectedAt): bool
    {
        $location = $asset->latestLocation;

        return $location?->speed !== null
            && MovementCriterion::isMoving($asset, (float) $location->speed)
            && $location->recorded_at->gte($lastConnectedAt->copy()->subMinutes(self::CONNECTIVITY_FRESHNESS_MINUTES));
    }

    /**
     * @return 'raised'|'within_threshold'|'disabled'|'already_raised'
     */
    private function inspectAsset(
        Asset $asset,
        CarbonInterface $lastConnectedAt,
        bool $wasInMotion,
        TenantConfigResolver $tenantConfig,
        StoreRawEvent $storeRawEvent,
        QueueRawEventForProcessing $queueForProcessing,
    ): string {
        $location = $asset->latestLocation;

        $threshold = $this->thresholdMinutesFor($asset, $tenantConfig, $wasInMotion);

        if ($threshold['minutes'] <= 0) {
            return 'disabled';
        }

        if ($lastConnectedAt->gt(now()->subMinutes($threshold['minutes']))) {
            return 'within_threshold';
        }

        $deduplicationKey = sprintf('offline:%d:%d', $asset->id, $lastConnectedAt->getTimestamp());

        $alreadyRaised = RawEvent::query()
            ->where('team_id', $asset->team_id)
            ->where('deduplication_key', $deduplicationKey)
            ->exists();

        if ($alreadyRaised) {
            SystemLog::skipped('assets.offline.skipped', reason: 'already_raised', input: [
                'team_id' => $asset->team_id,
                'asset_id' => $asset->id,
            ], result: ['deduplication_key' => $deduplicationKey], debug: true);

            return 'already_raised';
        }

        $silentMinutes = (int) $lastConnectedAt->diffInMinutes(now());

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
                'silent_minutes' => $silentMinutes,
                'threshold_minutes' => $threshold['minutes'],
                // Last known position so geofence context still works.
                'location' => $location !== null ? [
                    'latitude' => (float) $location->latitude,
                    'longitude' => (float) $location->longitude,
                ] : null,
                'was_in_motion' => $wasInMotion,
            ],
            sourceType: EventSourceType::InternalMonitor->value,
            teamId: $asset->team_id,
            providerId: null,
            deduplicationKey: $deduplicationKey,
            eventTypeRaw: self::EVENT_TYPE_CODE,
        );

        $queueForProcessing->execute($rawEvent);

        // Nunca nombre, clave ni posición del activo: la edad del último fix
        // (respecto de la última conexión) basta para explicar `was_in_motion`.
        SystemLog::ok('assets.offline.raised', input: [
            'team_id' => $asset->team_id,
            'asset_id' => $asset->id,
        ], calc: [
            'silent_minutes' => $silentMinutes,
            'threshold_minutes' => $threshold['minutes'],
            'threshold_source' => $threshold['source'],
            'threshold_applied' => $threshold['applied'],
            'in_motion_minutes' => $threshold['in_motion_minutes'],
            'parked_minutes' => $threshold['parked_minutes'],
            'was_in_motion' => $wasInMotion,
            'location_age_s' => $location?->recorded_at !== null
                ? (int) $location->recorded_at->diffInSeconds($lastConnectedAt)
                : null,
        ], result: [
            'raw_event_id' => $rawEvent->id,
            'job_requested' => true,
        ]);

        return 'raised';
    }

    /**
     * @return array{minutes: int, source: 'asset_override'|'tenant_setting', in_motion_minutes: int, parked_minutes: ?int, applied: 'in_motion'|'parked'|'disabled'}
     */
    private function thresholdMinutesFor(Asset $asset, TenantConfigResolver $tenantConfig, bool $wasInMotion): array
    {
        $override = ($asset->metadata_json ?? [])['offline_alert_minutes'] ?? null;
        $source = is_numeric($override) ? 'asset_override' : 'tenant_setting';

        $inMotion = is_numeric($override)
            ? (int) $override
            : (int) $tenantConfig->resolve($asset->team_id, self::SETTING_KEY, self::DEFAULT_OFFLINE_MINUTES);

        if ($inMotion <= 0 || $wasInMotion) {
            return [
                'minutes' => $inMotion,
                'source' => $source,
                'in_motion_minutes' => $inMotion,
                'parked_minutes' => null,
                'applied' => $inMotion <= 0 ? 'disabled' : 'in_motion',
            ];
        }

        $parked = (int) $tenantConfig->resolve(
            $asset->team_id,
            self::PARKED_SETTING_KEY,
            self::DEFAULT_PARKED_OFFLINE_MINUTES,
        );

        // Parked grace never undercuts the in-motion threshold.
        return [
            'minutes' => $parked <= 0 ? 0 : max($parked, $inMotion),
            'source' => $source,
            'in_motion_minutes' => $inMotion,
            'parked_minutes' => $parked,
            'applied' => $parked <= 0 ? 'disabled' : 'parked',
        ];
    }

    /**
     * A device that connected again (or an asset that reported a newer GPS
     * fix — also proof of life, and the only signal for episodes raised
     * before the connectivity feed existed) closes its offline episode: the episode's
     * normalized event is marked resolved at the source and the standard
     * external-resolution flow annotates (or closes, per tenant setting) the
     * incident it opened.
     */
    private function resolveRecoveredEpisodes(): int
    {
        $eventTypeId = EventType::query()->where('code', self::EVENT_TYPE_CODE)->value('id');

        if ($eventTypeId === null) {
            return 0;
        }

        $resolved = 0;

        NormalizedEvent::query()
            ->where('event_type_id', $eventTypeId)
            ->where('occurred_at', '>=', now()->subDays(7))
            ->whereNotNull('asset_id')
            ->with('asset')
            ->chunkById(200, function ($events) use (&$resolved) {
                foreach ($events as $event) {
                    if (($event->payload_normalized_json['is_resolved'] ?? null) === true) {
                        continue;
                    }

                    $proofOfLife = $this->lastProofOfLife($event->asset);

                    if ($proofOfLife === null || ! $proofOfLife['at']->gt($event->occurred_at)) {
                        continue;
                    }

                    $lastSeen = $proofOfLife['at'];

                    $payload = $event->payload_normalized_json ?? [];
                    $payload['is_resolved'] = true;
                    $payload['external_resolved_at'] = $lastSeen->toIso8601String();

                    $event->forceFill(['payload_normalized_json' => $payload])->save();

                    ApplyExternalResolutionJob::dispatch($event->id);

                    $resolved++;

                    TenantContext::for($event->team_id, function () use ($event, $proofOfLife, $lastSeen): void {
                        $silentSince = $this->episodeLastConnectedAt($event);

                        // `silent_minutes`: del latido congelado del episodio a la prueba
                        // de vida (null si el evento no lo guardó). `raised_to_recovery_minutes`:
                        // del aviso (`occurred_at`) a la prueba de vida.
                        SystemLog::ok('assets.offline.resolved', input: [
                            'team_id' => $event->team_id,
                            'normalized_event_id' => $event->id,
                            'asset_id' => $event->asset_id,
                        ], calc: [
                            'proof_of_life_source' => $proofOfLife['source'],
                            'silent_minutes' => $silentSince !== null ? (int) $silentSince->diffInMinutes($lastSeen) : null,
                            'raised_to_recovery_minutes' => (int) $event->occurred_at->diffInMinutes($lastSeen),
                        ], result: ['job_requested' => true]);
                    });
                }
            });

        return $resolved;
    }

    /**
     * The frozen heartbeat the episode was raised for. Only the raw event's
     * payload keeps it (internal normalization does not copy it), so it is
     * read from there — within the episode's tenant, and only for episodes
     * being resolved. Null for episodes raised before the connectivity feed.
     */
    private function episodeLastConnectedAt(NormalizedEvent $event): ?CarbonInterface
    {
        $payload = RawEvent::query()
            ->where('team_id', $event->team_id)
            ->whereKey($event->raw_event_id)
            ->value('payload_json');

        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        $lastConnectedAt = is_array($payload) ? ($payload['last_connected_at'] ?? null) : null;

        if (! is_string($lastConnectedAt) || $lastConnectedAt === '') {
            return null;
        }

        try {
            return Carbon::parse($lastConnectedAt);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The newest proof of life and where it came from: the device heartbeat
     * or a GPS fix (a tie keeps the heartbeat).
     *
     * @return array{at: CarbonInterface, source: 'heartbeat'|'gps_fix'}|null
     */
    private function lastProofOfLife(?Asset $asset): ?array
    {
        $candidates = array_filter([
            'heartbeat' => $asset?->device_last_connected_at,
            'gps_fix' => $asset?->last_seen_at,
        ]);

        $best = null;

        foreach ($candidates as $source => $time) {
            if ($best === null || $time->gt($best['at'])) {
                $best = ['at' => $time, 'source' => $source];
            }
        }

        return $best;
    }
}
