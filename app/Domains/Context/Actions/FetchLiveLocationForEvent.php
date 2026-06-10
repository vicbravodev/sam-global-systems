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

        if ($teamId === null || $normalizedEvent->asset_id === null) {
            return $noFetch;
        }

        if ($normalizedEvent->eventSeverity?->code !== 'critical') {
            return $noFetch;
        }

        $payloadLocation = Arr::get($normalizedEvent->payload_normalized_json ?? [], 'location');

        if (is_array($payloadLocation) && isset($payloadLocation['latitude'], $payloadLocation['longitude'])) {
            return $noFetch;
        }

        $stalenessSeconds = (int) $this->tenantConfigResolver->resolve(
            $teamId,
            self::SETTING_KEY,
            self::DEFAULT_STALENESS_SECONDS,
        );

        $latest = $normalizedEvent->asset?->latestLocation;

        if ($latest?->recorded_at !== null && $latest->recorded_at->gt(now()->subSeconds($stalenessSeconds))) {
            return $noFetch;
        }

        $live = $this->fetchFromProvider($normalizedEvent, $teamId);

        if ($live === null) {
            return ['location' => null, 'position_stale' => true];
        }

        $recordedAt = isset($live['recorded_at']) && $live['recorded_at'] !== null
            ? Carbon::parse($live['recorded_at'])
            : now();

        $this->updateAssetLocationSnapshot->execute(
            asset: $normalizedEvent->asset,
            latitude: (float) $live['latitude'],
            longitude: (float) $live['longitude'],
            source: LocationSource::Provider,
            recordedAt: $recordedAt,
            speed: isset($live['speed']) ? (float) $live['speed'] : null,
            heading: isset($live['heading']) ? (int) $live['heading'] : null,
            formattedLocation: $live['formatted_location'] ?? null,
        );

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

    /**
     * @return array<string, mixed>|null
     */
    private function fetchFromProvider(NormalizedEvent $normalizedEvent, int $teamId): ?array
    {
        $references = AssetExternalReference::query()
            ->where('asset_id', $normalizedEvent->asset_id)
            ->whereNotNull('external_id')
            ->get();

        foreach ($references as $reference) {
            $integration = TenantIntegration::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('provider_id', $reference->provider_id)
                ->where('status', TenantIntegrationStatus::Active)
                ->first();

            if ($integration === null) {
                continue;
            }

            $live = $this->providerAdapter->fetchLiveLocation($integration, (string) $reference->external_id);

            if ($live !== null) {
                return $live;
            }
        }

        return null;
    }
}
