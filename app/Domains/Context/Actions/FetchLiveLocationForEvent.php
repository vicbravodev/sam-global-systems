<?php

namespace App\Domains\Context\Actions;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Assets\Actions\UpdateAssetLocationSnapshot;
use App\Domains\Assets\Enums\LocationSource;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\SystemLog;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class FetchLiveLocationForEvent
{
    public const string SETTING_KEY = 'context.live_location_staleness_seconds';

    public const int DEFAULT_STALENESS_SECONDS = 60;

    public function __construct(
        private ProviderAdapter $providerAdapter,
        private TenantConfigResolver $tenantConfigResolver,
        private UpdateAssetLocationSnapshot $updateAssetLocationSnapshot,
    ) {}

    /**
     * Refresh the asset position from the provider for critical events whose
     * latest stored location is stale (Roadmap B6-P4).
     *
     * Gating: only critical severity, only when the event payload carries no
     * inline GPS, and only when `latestLocation` is older than the
     * `context.live_location_staleness_seconds` tenant setting (default 60).
     * A successful fetch is persisted as an `AssetLocationSnapshot` (it feeds
     * the fleet map) and returned with `source = live_fetch` so the context
     * snapshot records its provenance. Any failure degrades silently:
     * `location` stays null (callers fall back to `latestLocation`) and
     * `position_stale` is flagged so signals mark the GPS as weak.
     *
     * @return array{location: array<string, mixed>|null, position_stale: bool}
     */
    public function execute(NormalizedEvent $normalizedEvent): array
    {
        $noFetch = ['location' => null, 'position_stale' => false];

        $teamId = $normalizedEvent->team_id;

        if ($normalizedEvent->asset_id === null) {
            SystemLog::skipped('context.live_location.skipped', reason: 'no_asset', input: ['normalized_event_id' => $normalizedEvent->id]);

            return $noFetch;
        }

        if ($normalizedEvent->eventSeverity?->code !== 'critical') {
            SystemLog::skipped('context.live_location.skipped', reason: 'not_critical', input: [
                'normalized_event_id' => $normalizedEvent->id,
                'severity_code' => $normalizedEvent->eventSeverity?->code,
            ], debug: true);

            return $noFetch;
        }

        $payloadLocation = Arr::get($normalizedEvent->payload_normalized_json ?? [], 'location');

        if (is_array($payloadLocation) && isset($payloadLocation['latitude'], $payloadLocation['longitude'])) {
            SystemLog::skipped('context.live_location.skipped', reason: 'payload_has_gps', input: ['normalized_event_id' => $normalizedEvent->id]);

            return $noFetch;
        }

        $stalenessSeconds = (int) $this->tenantConfigResolver->resolve(
            $teamId,
            self::SETTING_KEY,
            self::DEFAULT_STALENESS_SECONDS,
        );

        $latest = $normalizedEvent->asset?->latestLocation;
        $age = $latest?->recorded_at !== null ? $this->ageSeconds($latest->recorded_at) : null;

        if ($latest?->recorded_at !== null && $latest->recorded_at->gt(now()->subSeconds($stalenessSeconds))) {
            SystemLog::skipped('context.live_location.skipped', reason: 'latest_location_fresh', input: [
                'normalized_event_id' => $normalizedEvent->id,
            ], calc: [
                'latest_age_seconds' => $age,
                'staleness_threshold_seconds' => $stalenessSeconds,
            ]);

            return $noFetch;
        }

        $fetch = $this->fetchFromProvider($normalizedEvent, $teamId);
        $live = $fetch['live'];

        if ($live === null) {
            SystemLog::degraded(
                'context.live_location.failed',
                reason: $fetch['integrations_tried'] === 0 ? 'no_active_integration' : 'provider_returned_nothing',
                input: [
                    'normalized_event_id' => $normalizedEvent->id,
                    'asset_id' => $normalizedEvent->asset_id,
                ],
                calc: [
                    'references' => $fetch['references'],
                    'integrations_tried' => $fetch['integrations_tried'],
                    'latest_age_seconds' => $age,
                    'staleness_threshold_seconds' => $stalenessSeconds,
                ],
                result: ['position_stale' => true],
            );

            return ['location' => null, 'position_stale' => true];
        }

        $providerTime = isset($live['recorded_at']);
        $recordedAt = $providerTime ? Carbon::parse($live['recorded_at']) : now();

        // Returns the existing row untouched when this fix is already stored.
        $stored = $this->updateAssetLocationSnapshot->execute(
            asset: $normalizedEvent->asset,
            latitude: (float) $live['latitude'],
            longitude: (float) $live['longitude'],
            source: LocationSource::Provider,
            recordedAt: $recordedAt,
            speed: isset($live['speed']) ? (float) $live['speed'] : null,
            heading: isset($live['heading']) ? (int) $live['heading'] : null,
            formattedLocation: $live['formatted_location'] ?? null,
        );

        SystemLog::ok('context.live_location.fetched', input: [
            'normalized_event_id' => $normalizedEvent->id,
            'asset_id' => $normalizedEvent->asset_id,
        ], calc: [
            'latest_age_seconds' => $age,
            'staleness_threshold_seconds' => $stalenessSeconds,
            // Without a provider time the fix is stamped "now": its age is unknown.
            'fix_age_seconds' => $providerTime ? $this->ageSeconds($recordedAt) : null,
            'fix_time_source' => $providerTime ? 'provider' : 'assumed_now',
        ], result: [
            'position_stale' => false,
            'snapshot_updated' => $stored->wasRecentlyCreated,
        ]);

        return [
            'location' => [
                'latitude' => (float) $live['latitude'],
                'longitude' => (float) $live['longitude'],
                'source' => 'live_fetch',
                'recorded_at' => $recordedAt->toIso8601String(),
            ],
            'position_stale' => false,
        ];
    }

    private function ageSeconds(\DateTimeInterface $moment): int
    {
        return (int) Carbon::instance($moment)->diffInSeconds(now(), false);
    }

    /**
     * @return array{live: array<string, mixed>|null, references: int, integrations_tried: int}
     */
    private function fetchFromProvider(NormalizedEvent $normalizedEvent, int $teamId): array
    {
        $references = AssetExternalReference::query()
            ->where('asset_id', $normalizedEvent->asset_id)
            ->whereNotNull('external_id')
            ->get();

        $tried = 0;

        foreach ($references as $reference) {
            $integration = TenantIntegration::query()
                ->where('team_id', $teamId)
                ->where('provider_id', $reference->provider_id)
                ->where('status', TenantIntegrationStatus::Active)
                ->first();

            if ($integration === null) {
                continue;
            }

            $tried++;

            $live = $this->providerAdapter->fetchLiveLocation($integration, (string) $reference->external_id);

            if ($live !== null) {
                return ['live' => $live, 'references' => $references->count(), 'integrations_tried' => $tried];
            }
        }

        return ['live' => null, 'references' => $references->count(), 'integrations_tried' => $tried];
    }
}
