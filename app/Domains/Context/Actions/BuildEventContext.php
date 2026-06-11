<?php

namespace App\Domains\Context\Actions;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Context\Enums\GeofenceMatchType;
use App\Domains\Context\Enums\IncidentRelationType;
use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\EventRecentHistorySnapshot;
use App\Domains\Context\Models\EventRelatedIncidentLink;
use App\Domains\Context\Models\GeofenceMatch;
use App\Domains\Context\Support\SignalsBuilder;
use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class BuildEventContext
{
    public const string SETTING_SAFETY_CORRELATION = 'context.safety_correlation_minutes';

    public const int DEFAULT_SAFETY_CORRELATION_MINUTES = 30;

    public function __construct(
        private ResolveGeofenceContext $resolveGeofenceContext,
        private LoadRecentAssetHistory $loadRecentAssetHistory,
        private GetRelatedOpenIncidents $getRelatedOpenIncidents,
        private GetPriorSimilarIncidents $getPriorSimilarIncidents,
        private ResolveDriverOperationalContext $resolveDriverOperationalContext,
        private BuildOperationalContextProfile $buildOperationalContextProfile,
        private FetchLiveLocationForEvent $fetchLiveLocationForEvent,
        private TenantConfigResolver $tenantConfigResolver,
    ) {}

    public function execute(NormalizedEvent $normalizedEvent): EventContextSnapshot
    {
        $normalizedEvent->loadMissing(['asset.latestLocation', 'driver', 'eventSeverity', 'eventType']);

        // Runs before the snapshot transaction: it makes an HTTP call to the
        // provider and persists its own AssetLocationSnapshot on success.
        $liveFetch = $this->fetchLiveLocationForEvent->execute($normalizedEvent);

        if ($liveFetch['location'] !== null) {
            $normalizedEvent->asset?->unsetRelation('latestLocation');
        }

        return DB::transaction(function () use ($normalizedEvent, $liveFetch) {
            $location = $this->extractLocation($normalizedEvent, $liveFetch['location']);
            $lat = $location['latitude'] ?? null;
            $lng = $location['longitude'] ?? null;

            $assetSnapshot = $this->assetSnapshot($normalizedEvent);
            $telemetrySnapshot = $this->telemetrySnapshot($normalizedEvent, $liveFetch['position_stale']);
            $driverSnapshot = $this->resolveDriverOperationalContext->execute(
                $normalizedEvent->driver_id,
                $normalizedEvent->occurred_at ?? now(),
            );
            $geofenceMatches = $this->resolveGeofenceContext->execute(
                is_numeric($lat) ? (float) $lat : null,
                is_numeric($lng) ? (float) $lng : null,
                $normalizedEvent->team_id,
            );
            $incidents = [
                ...$this->getRelatedOpenIncidents->execute($normalizedEvent)->all(),
                ...$this->getPriorSimilarIncidents->execute($normalizedEvent)->all(),
            ];
            $recentHistory = $this->loadRecentAssetHistory->execute(
                $normalizedEvent->asset_id,
                $normalizedEvent->event_type_id,
                $normalizedEvent->occurred_at ?? now(),
                correlationMinutes: max(1, (int) $this->tenantConfigResolver->resolve(
                    (int) $normalizedEvent->team_id,
                    self::SETTING_SAFETY_CORRELATION,
                    self::DEFAULT_SAFETY_CORRELATION_MINUTES,
                )),
                excludeEventId: $normalizedEvent->id,
            );

            $signals = SignalsBuilder::build([
                'geofence_matches' => $geofenceMatches,
                'incidents' => $incidents,
                'recent_history' => $recentHistory,
                'driver' => $driverSnapshot ?? [],
                'asset' => $assetSnapshot ?? [],
                'telemetry' => $telemetrySnapshot ?? [],
                'media' => [],
                'event' => [
                    'is_resolved' => $normalizedEvent->payload_normalized_json['is_resolved'] ?? null,
                ],
            ]);

            $existing = EventContextSnapshot::withoutGlobalScopes()
                ->where('normalized_event_id', $normalizedEvent->id)
                ->first();
            $nextVersion = $existing ? ((int) $existing->context_version + 1) : 1;

            $snapshot = EventContextSnapshot::withoutGlobalScopes()->updateOrCreate(
                ['normalized_event_id' => $normalizedEvent->id],
                [
                    'team_id' => $normalizedEvent->team_id,
                    'asset_id' => $normalizedEvent->asset_id,
                    'driver_id' => $normalizedEvent->driver_id,
                    'event_occurred_at' => $normalizedEvent->occurred_at ?? now(),
                    'context_version' => $nextVersion,
                    'location_snapshot_json' => $location,
                    'asset_snapshot_json' => $assetSnapshot,
                    'driver_snapshot_json' => $driverSnapshot,
                    'telemetry_snapshot_json' => $telemetrySnapshot,
                    'geofence_snapshot_json' => $this->normalizeGeofenceMatchesForStorage($geofenceMatches),
                    'incidents_snapshot_json' => $incidents,
                    'recent_history_snapshot_json' => $this->serializeRecentHistory($recentHistory),
                    'media_snapshot_json' => [],
                    'signals_json' => $signals,
                ],
            );

            GeofenceMatch::query()->where('normalized_event_id', $normalizedEvent->id)->delete();
            foreach ($geofenceMatches as $match) {
                GeofenceMatch::query()->create([
                    'normalized_event_id' => $normalizedEvent->id,
                    'geofence_id' => $match['geofence_id'],
                    'match_type' => $match['match_type'] instanceof GeofenceMatchType
                        ? $match['match_type']->value
                        : $match['match_type'],
                    'matched_at' => $normalizedEvent->occurred_at ?? now(),
                    'distance_meters' => $match['distance_meters'] ?? null,
                ]);
            }

            EventRecentHistorySnapshot::query()->updateOrCreate(
                ['normalized_event_id' => $normalizedEvent->id],
                [
                    'window_start' => $recentHistory['window_start'],
                    'window_end' => $recentHistory['window_end'],
                    'recent_events_count' => $recentHistory['recent_events_count'],
                    'recent_incidents_count' => $recentHistory['recent_incidents_count'],
                    'recent_same_type_count' => $recentHistory['recent_same_type_count'],
                    'recent_high_severity_count' => $recentHistory['recent_high_severity_count'],
                    'recent_locations_json' => $recentHistory['recent_locations_json'],
                    'recent_flags_json' => $recentHistory['recent_flags_json'],
                ],
            );

            $this->persistRelatedIncidentLinks($normalizedEvent, $incidents);

            $profile = $this->buildOperationalContextProfile->execute($snapshot->fresh());

            EventContextBuilt::dispatch($snapshot->fresh(), $profile);

            return $snapshot->fresh();
        });
    }

    /**
     * Location priority: inline GPS from the event payload, then a live fetch
     * from the provider (critical events with stale positions, B6-P4), then
     * the latest stored asset location.
     *
     * @param  array<string, mixed>|null  $liveLocation
     * @return array<string, mixed>
     */
    private function extractLocation(NormalizedEvent $event, ?array $liveLocation = null): array
    {
        $payloadLocation = Arr::get($event->payload_normalized_json ?? [], 'location');

        if (is_array($payloadLocation) && isset($payloadLocation['latitude'], $payloadLocation['longitude'])) {
            return [
                'latitude' => (float) $payloadLocation['latitude'],
                'longitude' => (float) $payloadLocation['longitude'],
                'source' => 'event_payload',
            ];
        }

        if ($liveLocation !== null) {
            return $liveLocation;
        }

        $latest = $event->asset?->latestLocation;

        if ($latest && $latest->latitude !== null && $latest->longitude !== null) {
            return [
                'latitude' => (float) $latest->latitude,
                'longitude' => (float) $latest->longitude,
                'source' => 'asset_latest_location',
                'recorded_at' => $latest->recorded_at?->toIso8601String(),
            ];
        }

        return [
            'latitude' => null,
            'longitude' => null,
            'source' => 'unknown',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function assetSnapshot(NormalizedEvent $event): ?array
    {
        $asset = $event->asset;

        if ($asset === null) {
            return null;
        }

        $metadata = $asset->metadata_json ?? [];

        return [
            'asset_id' => $asset->id,
            'name' => $asset->name,
            'code' => $asset->code,
            'status' => $asset->status?->value,
            'has_camera' => (bool) ($metadata['has_camera'] ?? false),
            'camera_status' => $metadata['camera_status'] ?? null,
            'last_seen_at' => $asset->last_seen_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function telemetrySnapshot(NormalizedEvent $event, bool $positionStale = false): ?array
    {
        $payload = $event->payload_normalized_json ?? [];
        $speedMeta = Arr::get($payload, 'speed_metadata');
        $latest = $event->asset?->latestLocation;

        if ($latest === null && $speedMeta === null && ! $positionStale) {
            return null;
        }

        return [
            'speed_kph' => is_array($speedMeta) ? Arr::get($speedMeta, 'currentSpeedKph') : ($latest?->speed !== null ? (float) $latest->speed : null),
            'recent_speeds' => [],
            'gps_accuracy_meters' => null,
            'position_stale' => $positionStale,
            'recorded_at' => $latest?->recorded_at?->toIso8601String(),
        ];
    }

    /**
     * Persist `EventRelatedIncidentLink` rows for each related open/recent
     * incident discovered by `GetRelatedOpenIncidents` and each closed prior
     * incident discovered by `GetPriorSimilarIncidents`. Uses `updateOrCreate`
     * keyed on (normalized_event_id, incident_id, relation_type) so re-running
     * the pipeline is idempotent and does not duplicate links.
     *
     * @param  array<int, array<string, mixed>>  $incidents
     */
    private function persistRelatedIncidentLinks(NormalizedEvent $normalizedEvent, array $incidents): void
    {
        if ($incidents === []) {
            EventRelatedIncidentLink::withoutGlobalScopes()
                ->where('normalized_event_id', $normalizedEvent->id)
                ->delete();

            return;
        }

        foreach ($incidents as $incident) {
            $incidentId = $incident['incident_id'] ?? null;

            if ($incidentId === null) {
                continue;
            }

            $relation = $this->resolveRelationType($normalizedEvent, $incident);

            EventRelatedIncidentLink::withoutGlobalScopes()->updateOrCreate(
                [
                    'normalized_event_id' => $normalizedEvent->id,
                    'incident_id' => $incidentId,
                    'relation_type' => $relation,
                ],
                [
                    'team_id' => $normalizedEvent->team_id,
                    'confidence_score' => 0.80,
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $incident
     */
    private function resolveRelationType(NormalizedEvent $normalizedEvent, array $incident): IncidentRelationType
    {
        if (($incident['relation'] ?? null) === IncidentRelationType::PriorSimilarIncident->value) {
            return IncidentRelationType::PriorSimilarIncident;
        }

        if ($normalizedEvent->asset_id !== null && ($incident['asset_id'] ?? null) === $normalizedEvent->asset_id) {
            return IncidentRelationType::SameAssetOpenIncident;
        }

        if ($normalizedEvent->driver_id !== null && ($incident['driver_id'] ?? null) === $normalizedEvent->driver_id) {
            return IncidentRelationType::SameDriverRecentIncident;
        }

        return IncidentRelationType::ProbableFollowup;
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<int, array<string, mixed>>
     */
    private function normalizeGeofenceMatchesForStorage(array $matches): array
    {
        return array_map(function (array $match) {
            return [
                'geofence_id' => $match['geofence_id'],
                'name' => $match['name'] ?? null,
                'code' => $match['code'] ?? null,
                'category' => $match['category'] instanceof \BackedEnum ? $match['category']->value : $match['category'],
                'match_type' => $match['match_type'] instanceof \BackedEnum ? $match['match_type']->value : $match['match_type'],
                'distance_meters' => $match['distance_meters'] ?? null,
            ];
        }, $matches);
    }

    /**
     * @param  array<string, mixed>  $recentHistory
     * @return array<string, mixed>
     */
    private function serializeRecentHistory(array $recentHistory): array
    {
        return [
            'window_start' => $recentHistory['window_start']?->toIso8601String(),
            'window_end' => $recentHistory['window_end']?->toIso8601String(),
            'recent_events_count' => $recentHistory['recent_events_count'],
            'recent_incidents_count' => $recentHistory['recent_incidents_count'],
            'recent_same_type_count' => $recentHistory['recent_same_type_count'],
            'recent_high_severity_count' => $recentHistory['recent_high_severity_count'],
            'repeated_panic_count_24h' => $recentHistory['repeated_panic_count_24h'] ?? 0,
            'nearby_safety_events_count' => $recentHistory['nearby_safety_events_count'] ?? 0,
            'nearby_safety_breakdown' => $recentHistory['nearby_safety_breakdown'] ?? [],
            'harsh_driving_near_event' => $recentHistory['harsh_driving_near_event'] ?? false,
            'recent_locations' => $recentHistory['recent_locations_json'],
        ];
    }
}
