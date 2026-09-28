<?php

namespace App\Domains\Integrations\Adapters;

use App\Contracts\Integrations\MediaRetrievalAdapter;
use App\Domains\Assets\Enums\TelematicsFeed;
use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Data\VehicleStatsPage;
use App\Domains\Integrations\Exceptions\ProviderCursorRejected;
use App\Domains\Integrations\Exceptions\ProviderCursorRejectedException;
use App\Domains\Integrations\Exceptions\ProviderRateLimited;
use App\Domains\Integrations\Exceptions\ProviderRequestFailedException;
use App\Domains\Integrations\Exceptions\ProviderUnauthorized;
use App\Domains\Integrations\Exceptions\ProviderUnavailable;
use App\Domains\Integrations\Models\TenantIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Real adapter for Samsara's Fleet API (https://developers.samsara.com).
 *
 * - testConnection / sync authenticate with a bearer API token resolved from
 *   the tenant integration's credentials.
 * - validateWebhookSignature verifies Samsara's HMAC-SHA256 signature.
 */
class SamsaraAdapter implements MediaRetrievalAdapter, ProviderAdapter
{
    /**
     * Hard cap on pagination pages per sync call to avoid runaway loops.
     */
    private const MAX_PAGES = 50;

    /**
     * Samsara stat type -> [our type, unit, divisor, decimals].
     *
     * Samsara reports these in base units (metres, millivolts, millidegrees);
     * the divisor converts to what the domain stores and the UI renders. A null
     * divisor marks a categorical stat kept verbatim.
     *
     * `speed` is deliberately absent: it already arrives with every GPS reading
     * and is persisted as a location snapshot, so ingesting it again here would
     * duplicate the same measurement in two tables.
     */
    private const TELEMETRY_STAT_MAP = [
        'engineStates' => [TelemetryType::Ignition, null, null, 0],
        'fuelPercents' => [TelemetryType::Fuel, '%', 1, 0],
        'obdOdometerMeters' => [TelemetryType::Odometer, 'km', 1000, 1],
        'batteryMilliVolts' => [TelemetryType::Battery, 'V', 1000, 2],
        'ambientAirTemperatureMilliC' => [TelemetryType::Temperature, '°C', 1000, 1],
    ];

    /**
     * Stat types per feed. Samsara caps `types` at 3 per request, so each feed
     * is exactly one request per page and follows its own cursor.
     */
    private const FEED_TYPES = [
        'motion' => ['gps', 'engineStates', 'fuelPercents'],
        'diagnostics' => ['obdOdometerMeters', 'batteryMilliVolts', 'ambientAirTemperatureMilliC'],
    ];

    public function testConnection(TenantIntegration $integration): array
    {
        $token = $this->resolveToken($integration);

        if ($token === null) {
            return ['success' => false, 'message' => 'No hay token de API configurado para esta integración de Samsara.'];
        }

        try {
            $response = $this->client($token)->get('/fleet/vehicles', ['limit' => 1]);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Could not reach Samsara: '.$e->getMessage()];
        }

        if ($response->successful()) {
            return ['success' => true, 'message' => 'Connected to Samsara successfully.'];
        }

        if (in_array($response->status(), [401, 403], true)) {
            return ['success' => false, 'message' => 'Samsara rejected the API token (HTTP '.$response->status().').'];
        }

        return ['success' => false, 'message' => 'Samsara returned HTTP '.$response->status().'.'];
    }

    public function sync(TenantIntegration $integration, string $type): array
    {
        $token = $this->resolveToken($integration);

        if ($token === null) {
            return ['assets' => [], 'drivers' => [], 'events' => [], 'records_processed' => 0];
        }

        $assets = $this->fetchPaginated($token, '/fleet/vehicles', fn (array $vehicle) => $this->mapVehicle($vehicle));
        $drivers = $this->fetchPaginated($token, '/fleet/drivers', fn (array $driver) => $this->mapDriver($driver));

        return [
            'assets' => $assets,
            'drivers' => $drivers,
            // Samsara delivers operational events via webhooks, not via the
            // sync pull, so the events bucket is intentionally empty here.
            'events' => [],
            'records_processed' => count($assets) + count($drivers),
        ];
    }

    public function fetchVehicleStatsFeed(TenantIntegration $integration, TelematicsFeed $feed, ?string $cursor = null): VehicleStatsPage
    {
        $token = $this->resolveToken($integration);

        if ($token === null) {
            throw new ProviderUnauthorized('No hay token de API configurado para esta integración de Samsara.');
        }

        $query = ['types' => implode(',', self::FEED_TYPES[$feed->value])];

        if ($cursor !== null && $cursor !== '') {
            $query['after'] = $cursor;
        }

        return $this->statsPage($token, '/fleet/vehicles/stats/feed', $query, $feed, cursorSent: isset($query['after']));
    }

    public function fetchVehicleStatsHistory(
        TenantIntegration $integration,
        TelematicsFeed $feed,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
        ?string $cursor = null,
    ): VehicleStatsPage {
        $token = $this->resolveToken($integration);

        if ($token === null) {
            throw new ProviderUnauthorized('No hay token de API configurado para esta integración de Samsara.');
        }

        $query = [
            'types' => implode(',', self::FEED_TYPES[$feed->value]),
            'startTime' => Carbon::instance($start)->utc()->toIso8601ZuluString(),
            'endTime' => Carbon::instance($end)->utc()->toIso8601ZuluString(),
        ];

        if ($cursor !== null && $cursor !== '') {
            $query['after'] = $cursor;
        }

        return $this->statsPage($token, '/fleet/vehicles/stats/history', $query, $feed, cursorSent: false);
    }

    /**
     * One request to a stats endpoint, mapped to a page. Every failure becomes
     * a typed exception so the caller can pause, back off or resync without
     * knowing Samsara's status codes.
     *
     * @param  array<string, string>  $query
     */
    private function statsPage(string $token, string $path, array $query, TelematicsFeed $feed, bool $cursorSent): VehicleStatsPage
    {
        try {
            $response = $this->client($token)
                ->connectTimeout((int) config('telematics.http.connect_timeout', 2))
                ->timeout((int) config('telematics.http.timeout', 8))
                ->get($path, $query);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable('Could not reach Samsara: '.$e->getMessage(), previous: $e);
        }

        $status = $response->status();

        if ($status === 429) {
            throw new ProviderRateLimited(max(0.0, (float) ($response->header('Retry-After') ?: 1)));
        }

        if ($status === 401 || $status === 403) {
            throw new ProviderUnauthorized("Samsara rejected the API token (HTTP {$status}).");
        }

        if ($status >= 500) {
            throw new ProviderUnavailable("Samsara returned HTTP {$status}.");
        }

        if (! $response->successful()) {
            // A 4xx on a cursored feed call is the cursor itself: Samsara
            // expires cursors after 30 days and rejects malformed ones.
            if ($cursorSent) {
                throw new ProviderCursorRejected("Samsara rejected the feed cursor (HTTP {$status}).");
            }

            throw new ProviderUnavailable("Samsara returned HTTP {$status}.");
        }

        $locations = [];
        $readings = [];

        foreach ((array) $response->json('data', []) as $record) {
            $record = (array) $record;
            $id = Arr::get($record, 'id');

            if (! is_scalar($id) || (string) $id === '') {
                continue;
            }

            foreach (self::FEED_TYPES[$feed->value] as $statType) {
                // Feed and history nest every point of a stat in a list; a
                // snapshot-style response carries a single object.
                $points = Arr::get($record, $statType);

                if (! is_array($points)) {
                    continue;
                }

                foreach (array_is_list($points) ? $points : [$points] as $point) {
                    if (! is_array($point)) {
                        continue;
                    }

                    $single = ['id' => $id, $statType => $point];

                    if ($statType === 'gps') {
                        $mapped = $this->mapVehicleLocation($single);

                        if ($mapped !== null) {
                            $locations[] = $mapped;
                        }

                        continue;
                    }

                    $mapped = $this->mapTelemetryReading($single, $statType);

                    if ($mapped !== null) {
                        $readings[] = $mapped;
                    }
                }
            }
        }

        $endCursor = $response->json('pagination.endCursor');

        return new VehicleStatsPage(
            locations: $locations,
            readings: $readings,
            endCursor: is_string($endCursor) && $endCursor !== '' ? $endCursor : null,
            hasNextPage: (bool) $response->json('pagination.hasNextPage', false),
        );
    }

    /**
     * Connectivity of every activated gateway, reduced to one entry per asset.
     *
     * `GET /gateways` lists every device in the org — vehicle gateways (VG),
     * asset gateways (AG), dashcams (CM) and asset tags (AT) — each with the
     * Samsara asset it is installed on and a `connectionStatus` holding
     * `lastConnected` (the device heartbeat, which keeps ticking while a
     * vehicle is parked with the engine off) and `healthStatus`. An asset with
     * several devices is represented by its telematics gateway (VG/AG): a
     * dashcam or tag only stands in when the asset has no gateway.
     *
     * Vehicles with no activated gateway are simply absent from the result.
     *
     * @return array<int, array{external_id: string, last_connected_at: string|null, health_status: string|null, serial: string|null, model: string|null}>
     */
    public function fetchDeviceConnectivity(TenantIntegration $integration): array
    {
        $token = $this->resolveToken($integration);

        if ($token === null) {
            return [];
        }

        $byAsset = [];
        $cursor = null;
        $pages = 0;

        do {
            $query = $cursor !== null ? ['after' => $cursor] : [];

            $response = $this->client($token)->get('/gateways', $query);

            if (! $response->successful()) {
                // A partial listing would make the missing assets look stale;
                // report nothing so the watchdog keeps its last good reading.
                return [];
            }

            foreach ((array) $response->json('data', []) as $gateway) {
                $mapped = $this->mapGatewayConnectivity((array) $gateway);

                if ($mapped === null) {
                    continue;
                }

                $current = $byAsset[$mapped['external_id']] ?? null;

                if ($current === null || $this->gatewayOutranks($mapped, $current)) {
                    $byAsset[$mapped['external_id']] = $mapped;
                }
            }

            $cursor = $response->json('pagination.endCursor');
            $hasNext = (bool) $response->json('pagination.hasNextPage', false);
            $pages++;
        } while ($hasNext && $cursor && $pages < self::MAX_PAGES);

        return array_values($byAsset);
    }

    /**
     * @param  array<string, mixed>  $gateway
     * @return array{external_id: string, last_connected_at: string|null, health_status: string|null, serial: string|null, model: string|null}|null
     */
    private function mapGatewayConnectivity(array $gateway): ?array
    {
        $assetId = Arr::get($gateway, 'asset.id');

        if (! is_scalar($assetId) || (string) $assetId === '') {
            return null;
        }

        $lastConnected = Arr::get($gateway, 'connectionStatus.lastConnected');
        $health = Arr::get($gateway, 'connectionStatus.healthStatus');

        return [
            'external_id' => (string) $assetId,
            'last_connected_at' => is_string($lastConnected) && $lastConnected !== '' ? $lastConnected : null,
            'health_status' => is_string($health) && $health !== '' ? $health : null,
            'serial' => is_string($gateway['serial'] ?? null) ? $gateway['serial'] : null,
            'model' => is_string($gateway['model'] ?? null) ? $gateway['model'] : null,
        ];
    }

    /**
     * Whether `$candidate` should represent its asset instead of `$current`:
     * a telematics gateway (VG/AG) beats a dashcam or tag, and within the same
     * rank the most recent heartbeat wins.
     *
     * @param  array{last_connected_at: string|null, model: string|null}  $candidate
     * @param  array{last_connected_at: string|null, model: string|null}  $current
     */
    private function gatewayOutranks(array $candidate, array $current): bool
    {
        $rank = fn (?string $model): int => $model !== null && preg_match('/^(VG|AG)/', $model) === 1 ? 1 : 0;

        if ($rank($candidate['model']) !== $rank($current['model'])) {
            return $rank($candidate['model']) > $rank($current['model']);
        }

        $time = fn (?string $iso): float => $iso !== null ? (float) Carbon::parse($iso)->format('U.u') : 0.0;

        return $time($candidate['last_connected_at']) > $time($current['last_connected_at']);
    }

    /**
     * Map one stat off a vehicle record, converting to the unit we store.
     *
     * Returns null when the vehicle does not report this stat — Samsara omits
     * the key entirely for vehicles without diagnostic coverage.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null
     */
    private function mapTelemetryReading(array $record, string $statType): ?array
    {
        $stat = Arr::get($record, $statType);

        // History-style responses nest a list of readings; take the newest.
        if (is_array($stat) && array_is_list($stat)) {
            $stat = end($stat) ?: null;
        }

        if (! is_array($stat)) {
            return null;
        }

        $value = Arr::get($stat, 'value');

        if ($value === null) {
            return null;
        }

        [$type, $unit, $divisor, $decimals] = self::TELEMETRY_STAT_MAP[$statType];

        return [
            'external_id' => (string) Arr::get($record, 'id'),
            'type' => $type,
            // A null divisor marks a categorical stat (engine state), which is
            // stored verbatim instead of being treated as a measurement.
            'value' => $divisor === null ? (string) $value : round((float) $value / $divisor, $decimals),
            'unit' => $unit,
            'recorded_at' => Arr::get($stat, 'time'),
        ];
    }

    /**
     * Fetch the live position of a single vehicle.
     *
     * Uses `GET /fleet/vehicles/locations?vehicleIds={id}` with a short timeout:
     * this runs inline in the context-enrichment pipeline for critical events,
     * so a slow provider must degrade to the stored location, never block it.
     * Returns null on any failure (no token, HTTP error, timeout, no GPS).
     */
    public function fetchLiveLocation(TenantIntegration $integration, string $externalAssetId): ?array
    {
        $token = $this->resolveToken($integration);

        if ($token === null || $externalAssetId === '') {
            return null;
        }

        try {
            $response = $this->client($token)
                ->timeout((int) config('services.samsara.live_location_timeout', 3))
                ->get('/fleet/vehicles/locations', ['vehicleIds' => $externalAssetId]);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $record = (array) ($response->json('data.0') ?? []);
        $location = $record['location'] ?? null;

        if (is_array($location) && array_is_list($location)) {
            $location = end($location) ?: null;
        }

        if (! is_array($location)) {
            return null;
        }

        $latitude = Arr::get($location, 'latitude');
        $longitude = Arr::get($location, 'longitude');

        if ($latitude === null || $longitude === null) {
            return null;
        }

        $speed = Arr::get($location, 'speed', Arr::get($location, 'speedMilesPerHour'));
        $heading = Arr::get($location, 'heading', Arr::get($location, 'headingDegrees'));

        return [
            'external_id' => (string) Arr::get($record, 'id', $externalAssetId),
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
            'speed' => self::mphToKph($speed),
            'heading' => $heading !== null ? (int) round((float) $heading) : null,
            'formatted_location' => Arr::get($location, 'reverseGeo.formattedLocation'),
            'recorded_at' => Arr::get($location, 'time'),
        ];
    }

    /**
     * Fetch safety events from `GET /safety-events/stream`.
     *
     * The stream is keyed by `updatedAtTime`, so the same event reappears when
     * its state changes (e.g. needsReview → dismissed); callers dedup on
     * `{id}:{eventState}` to let state transitions through.
     *
     * Samsara's pagination contract (verified against the live API):
     * `startTime` is REQUIRED on every request, including pages fetched with
     * `after`, and it must be byte-identical to the `startTime` of the request
     * that produced the cursor — otherwise Samsara answers 400 ("Parameters
     * differ from previous paginated request"). So the exact `startTime`
     * string is pinned for the lifetime of a cursor: pass back the
     * `start_time` returned here together with the cursor on the next poll.
     *
     * Pages are followed while `hasNextPage` (capped at MAX_PAGES; `has_more`
     * reports whether the cap cut the run short). Any non-2xx aborts the whole
     * call with an exception so the caller never persists a cursor over a
     * failed request; a rejected/expired cursor raises
     * {@see ProviderCursorRejectedException} so the caller can restart.
     *
     * @return array{events: array<int, array<string, mixed>>, cursor: string|null, start_time: string, has_more: bool}
     *
     * @throws ProviderRequestFailedException
     */
    public function fetchSafetyEvents(TenantIntegration $integration, ?string $cursor = null, \DateTimeInterface|string|null $startTime = null): array
    {
        $startTime = is_string($startTime) && $startTime !== ''
            ? $startTime
            : Carbon::instance($startTime instanceof \DateTimeInterface ? $startTime : now()->subDay())->toIso8601String();

        $cursor = $cursor !== null && $cursor !== '' ? $cursor : null;
        $token = $this->resolveToken($integration);

        if ($token === null) {
            return ['events' => [], 'cursor' => $cursor, 'start_time' => $startTime, 'has_more' => false];
        }

        $events = [];
        $pages = 0;

        do {
            $query = ['startTime' => $startTime];

            if ($cursor !== null) {
                $query['after'] = $cursor;
            }

            $response = $this->client($token)->get('/safety-events/stream', $query);

            if (! $response->successful()) {
                throw $this->safetyStreamFailure($response, isset($query['after']));
            }

            foreach ((array) $response->json('data', []) as $record) {
                $events[] = (array) $record;
            }

            $endCursor = $response->json('pagination.endCursor');

            if (is_string($endCursor) && $endCursor !== '') {
                $cursor = $endCursor;
            }

            $hasNext = (bool) $response->json('pagination.hasNextPage', false);
            $pages++;
        } while ($hasNext && $cursor !== null && $pages < self::MAX_PAGES);

        return [
            'events' => $events,
            'cursor' => $cursor,
            'start_time' => $startTime,
            'has_more' => $hasNext && $cursor !== null,
        ];
    }

    /**
     * Classify a failed `/safety-events/stream` response. A 400 about the
     * cursor or about the parameters differing from the paginated request
     * means the persisted cursor is unusable, not that the feed is down.
     */
    private function safetyStreamFailure(Response $response, bool $sentCursor): ProviderRequestFailedException
    {
        $message = strtolower((string) $response->json('message', ''));

        $cursorRejected = $sentCursor
            && $response->status() === 400
            && (str_contains($message, 'cursor') || str_contains($message, 'parameters differ'));

        return $cursorRejected
            ? ProviderCursorRejectedException::fromResponse('GET /safety-events/stream', $response)
            : ProviderRequestFailedException::fromResponse('GET /safety-events/stream', $response);
    }

    /**
     * Place a camera media retrieval (`POST /cameras/media/retrieval`).
     *
     * Returns Samsara's `retrievalId` to poll with {@see checkMedia}, or null
     * on any failure so callers can mark the media request as failed.
     */
    public function requestMedia(
        TenantIntegration $integration,
        string $externalAssetId,
        \DateTimeInterface $startTime,
        \DateTimeInterface $endTime,
        array $inputs = [],
        string $mediaType = 'videoHighRes',
    ): ?string {
        $token = $this->resolveToken($integration);

        if ($token === null || $externalAssetId === '') {
            return null;
        }

        try {
            $response = $this->client($token)->post('/cameras/media/retrieval', [
                'vehicleId' => $externalAssetId,
                'inputs' => $inputs !== [] ? $inputs : ['dashcamRoadFacing', 'dashcamDriverFacing'],
                'startTime' => Carbon::instance($startTime)->toIso8601String(),
                'endTime' => Carbon::instance($endTime)->toIso8601String(),
                'mediaType' => $mediaType,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Samsara media retrieval request failed', [
                'vehicle_id' => $externalAssetId,
                'media_type' => $mediaType,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Samsara rejected media retrieval request', [
                'vehicle_id' => $externalAssetId,
                'media_type' => $mediaType,
                'http_status' => $response->status(),
                'message' => $response->json('message'),
                'request_id' => $response->json('requestId'),
            ]);

            return null;
        }

        $retrievalId = $response->json('data.retrievalId');

        return is_string($retrievalId) && $retrievalId !== '' ? $retrievalId : null;
    }

    /**
     * Poll a media retrieval (`GET /cameras/media/retrieval?retrievalId=...`).
     */
    public function checkMedia(TenantIntegration $integration, string $retrievalId): array
    {
        $token = $this->resolveToken($integration);

        if ($token === null || $retrievalId === '') {
            return ['items' => []];
        }

        try {
            $response = $this->client($token)->get('/cameras/media/retrieval', ['retrievalId' => $retrievalId]);
        } catch (\Throwable $e) {
            Log::warning('Samsara media retrieval poll failed', [
                'retrieval_id' => $retrievalId,
                'error' => $e->getMessage(),
            ]);

            return ['items' => []];
        }

        if (! $response->successful()) {
            Log::warning('Samsara rejected media retrieval poll', [
                'retrieval_id' => $retrievalId,
                'http_status' => $response->status(),
                'message' => $response->json('message'),
                'request_id' => $response->json('requestId'),
            ]);

            return ['items' => []];
        }

        $items = [];

        foreach ((array) $response->json('data.media', []) as $media) {
            $media = (array) $media;

            $items[] = [
                'input' => Arr::get($media, 'input'),
                'status' => $this->normalizeMediaStatus((string) Arr::get($media, 'status', 'pending')),
                'url' => Arr::get($media, 'urlInfo.url') ?? Arr::get($media, 'url'),
            ];
        }

        return ['items' => $items];
    }

    /**
     * List media already uploaded by the device (`GET /cameras/media`) —
     * panic-button and safety-event footage is auto-uploaded by the dashcam
     * and only discoverable here, never via webhooks. Listing is quota-free.
     *
     * The response uses a different input vocabulary than retrievals
     * (`dashcamForwardFacing`/`dashcamInwardFacing`); inputs are normalized to
     * the retrieval names so downstream filename mapping stays uniform.
     */
    public function listUploadedMedia(
        TenantIntegration $integration,
        string $externalAssetId,
        \DateTimeInterface $startTime,
        \DateTimeInterface $endTime,
        array $triggerReasons = [],
    ): array {
        $token = $this->resolveToken($integration);

        if ($token === null || $externalAssetId === '') {
            return ['items' => []];
        }

        // `triggerReasons` is an exploded form param (`triggerReasons=a&triggerReasons=b`);
        // Samsara rejects both comma-joined and PHP's bracketed array encoding.
        $pairs = [
            'vehicleIds='.urlencode($externalAssetId),
            'startTime='.urlencode(Carbon::instance($startTime)->toIso8601String()),
            'endTime='.urlencode(Carbon::instance($endTime)->toIso8601String()),
        ];

        foreach ($triggerReasons as $reason) {
            $pairs[] = 'triggerReasons='.urlencode($reason);
        }

        try {
            $response = $this->client($token)->get('/cameras/media?'.implode('&', $pairs));
        } catch (\Throwable $e) {
            Log::warning('Samsara uploaded-media listing failed', [
                'vehicle_id' => $externalAssetId,
                'error' => $e->getMessage(),
            ]);

            return ['items' => []];
        }

        if (! $response->successful()) {
            Log::warning('Samsara rejected uploaded-media listing', [
                'vehicle_id' => $externalAssetId,
                'http_status' => $response->status(),
                'message' => $response->json('message'),
                'request_id' => $response->json('requestId'),
            ]);

            return ['items' => []];
        }

        $items = [];

        foreach ((array) $response->json('data.media', []) as $media) {
            $media = (array) $media;

            $items[] = [
                'input' => $this->normalizeUploadedInput(Arr::get($media, 'input')),
                'status' => Arr::get($media, 'urlInfo.url') ? 'available' : 'pending',
                'url' => Arr::get($media, 'urlInfo.url'),
                'media_type' => Arr::get($media, 'mediaType'),
                'trigger_reason' => Arr::get($media, 'triggerReason'),
                'start_time' => Arr::get($media, 'startTime'),
            ];
        }

        return ['items' => $items];
    }

    private function normalizeUploadedInput(?string $input): ?string
    {
        return match ($input) {
            'dashcamForwardFacing' => 'dashcamRoadFacing',
            'dashcamInwardFacing' => 'dashcamDriverFacing',
            default => $input,
        };
    }

    private function normalizeMediaStatus(string $status): string
    {
        return match (strtolower($status)) {
            'available' => 'available',
            'failed' => 'failed',
            default => 'pending',
        };
    }

    /**
     * Verify Samsara's webhook signature.
     *
     * Samsara sends `X-Samsara-Signature: v1=<hex>` plus an `X-Samsara-Timestamp`
     * (Unix seconds). The HMAC-SHA256 is computed over the signed message
     * `v1:{timestamp}:{rawBody}` using the webhook's Secret Key. See
     * https://developers.samsara.com/docs/webhooks#webhook-signatures.
     *
     * Samsara's dashboard Secret Key is Base64-encoded and must be decoded
     * before being used as the HMAC key, so we verify against the decoded key
     * first and fall back to the raw secret (for generic providers / secrets
     * already stored in decoded form).
     *
     * When no timestamp is supplied (generic providers / legacy callers) we fall
     * back to a plain HMAC over the raw body, still accepting either the "v1="
     * prefixed or raw-hex signature form.
     */
    public function validateWebhookSignature(string $payload, string $signature, string $secret, ?string $timestamp = null): bool
    {
        $provided = str_starts_with($signature, 'v1=') ? substr($signature, 3) : $signature;

        if ($provided === '') {
            return false;
        }

        if ($timestamp !== null && $timestamp !== '') {
            if (! $this->timestampWithinTolerance($timestamp)) {
                return false;
            }

            $message = 'v1:'.$timestamp.':'.$payload;
        } else {
            $message = $payload;
        }

        foreach ($this->candidateSecrets($secret) as $key) {
            if (hash_equals(hash_hmac('sha256', $message, $key), $provided)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Candidate HMAC keys to try, in priority order.
     *
     * Samsara's Secret Key is Base64-encoded and must be decoded before use, so
     * the decoded bytes are tried first. The raw secret is also tried so callers
     * that store an already-decoded or non-Base64 secret keep working.
     *
     * @return array<int, string>
     */
    private function candidateSecrets(string $secret): array
    {
        $candidates = [$secret];

        $decoded = base64_decode($secret, true);

        if ($decoded !== false && $decoded !== '' && $decoded !== $secret) {
            array_unshift($candidates, $decoded);
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Reject stale timestamps to protect against replay attacks. A tolerance of
     * 0 (config `services.samsara.webhook_tolerance_seconds`) disables the check.
     *
     * Samsara sends `X-Samsara-Timestamp` in seconds; we defensively also accept
     * a millisecond-precision value.
     */
    private function timestampWithinTolerance(string $timestamp): bool
    {
        $tolerance = (int) config('services.samsara.webhook_tolerance_seconds', 300);

        if ($tolerance <= 0) {
            return true;
        }

        if (! is_numeric($timestamp)) {
            return false;
        }

        $value = (int) $timestamp;
        // Treat <= 10-digit values as seconds, otherwise milliseconds.
        $seconds = $value > 9_999_999_999 ? intdiv($value, 1000) : $value;

        return abs(now()->getTimestamp() - $seconds) <= $tolerance;
    }

    /**
     * Fetch every page of a Samsara list endpoint and map each record.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $map
     * @return array<int, array<string, mixed>>
     */
    private function fetchPaginated(string $token, string $path, callable $map): array
    {
        $records = [];
        $cursor = null;
        $pages = 0;

        do {
            $query = ['limit' => 100];

            if ($cursor !== null) {
                $query['after'] = $cursor;
            }

            $response = $this->client($token)->get($path, $query);

            if (! $response->successful()) {
                // A silent partial listing would mark the sync completed with
                // missing (or zero) records; fail it so the error is recorded.
                throw ProviderRequestFailedException::fromResponse("GET {$path}", $response);
            }

            foreach ((array) $response->json('data', []) as $record) {
                $records[] = $map((array) $record);
            }

            $cursor = $response->json('pagination.endCursor');
            $hasNext = (bool) $response->json('pagination.hasNextPage', false);
            $pages++;
        } while ($hasNext && $cursor && $pages < self::MAX_PAGES);

        return $records;
    }

    /**
     * @param  array<string, mixed>  $vehicle
     * @return array<string, mixed>
     */
    private function mapVehicle(array $vehicle): array
    {
        $cameraSerial = Arr::get($vehicle, 'cameraSerial');

        return [
            'external_id' => (string) Arr::get($vehicle, 'id'),
            'name' => Arr::get($vehicle, 'name'),
            'vin' => Arr::get($vehicle, 'vin'),
            'license_plate' => Arr::get($vehicle, 'licensePlate'),
            // The hardware actually installed on the vehicle, keyed by serial so
            // the sync can reconcile it across ticks. Always present (possibly
            // empty) — an omitted key means "not reported" to the sync, which
            // then leaves existing devices alone.
            'devices' => $this->mapVehicleDevices($vehicle, $cameraSerial),
            // `has_camera` gates the panic-media auto-request listener and the
            // context signals — a vehicle with a paired CM dashcam reports its
            // serial here.
            'metadata' => array_filter([
                'has_camera' => is_string($cameraSerial) && $cameraSerial !== '',
                'camera_serial' => $cameraSerial,
                'make' => Arr::get($vehicle, 'make'),
                'model' => Arr::get($vehicle, 'model'),
                'year' => Arr::get($vehicle, 'year'),
                'serial' => Arr::get($vehicle, 'serial'),
                // Plate and VIN also travel in metadata: the asset sync only
                // persists `metadata`, and the fleet UI shows both.
                'license_plate' => Arr::get($vehicle, 'licensePlate'),
                'vin' => Arr::get($vehicle, 'vin'),
            ], fn ($value) => $value !== null && $value !== ''),
            'raw' => $vehicle,
        ];
    }

    /**
     * Devices installed on a vehicle: the telematics gateway and, when the
     * vehicle is camera-equipped, the dashcam.
     *
     * Samsara reports the gateway serial both nested under `gateway` (with its
     * model) and duplicated at the root as `serial`; the nested form wins and the
     * root is the fallback for older payloads.
     *
     * @param  array<string, mixed>  $vehicle
     * @return array<int, array{device_type: string, external_device_id: string, metadata: array<string, mixed>}>
     */
    private function mapVehicleDevices(array $vehicle, ?string $cameraSerial): array
    {
        $devices = [];

        $gatewaySerial = Arr::get($vehicle, 'gateway.serial') ?? Arr::get($vehicle, 'serial');

        if (is_string($gatewaySerial) && $gatewaySerial !== '') {
            $devices[] = [
                'device_type' => 'gateway',
                'external_device_id' => $gatewaySerial,
                'metadata' => array_filter([
                    'model' => Arr::get($vehicle, 'gateway.model'),
                ], fn ($value) => $value !== null && $value !== ''),
            ];
        }

        if (is_string($cameraSerial) && $cameraSerial !== '') {
            $devices[] = [
                'device_type' => 'camera',
                'external_device_id' => $cameraSerial,
                'metadata' => [],
            ];
        }

        return $devices;
    }

    /**
     * @param  array<string, mixed>  $driver
     * @return array<string, mixed>
     */
    private function mapDriver(array $driver): array
    {
        $tags = Arr::get($driver, 'tags');
        $tagNames = is_array($tags)
            ? array_values(array_filter(array_map(
                fn ($tag) => is_array($tag) ? Arr::get($tag, 'name') : null,
                $tags,
            ), fn ($name) => is_string($name) && $name !== ''))
            : [];

        return [
            'external_id' => (string) Arr::get($driver, 'id'),
            'name' => Arr::get($driver, 'name'),
            'username' => Arr::get($driver, 'username'),
            'phone' => Arr::get($driver, 'phone'),
            // Profile facts the Samsara driver record carries and the driver
            // detail page surfaces (license, username, timezone, tags...).
            // The driver sync persists `metadata` as-is into `metadata_json`.
            'metadata' => array_filter([
                'license_number' => Arr::get($driver, 'licenseNumber'),
                'license_state' => Arr::get($driver, 'licenseState'),
                'username' => Arr::get($driver, 'username'),
                'activation_status' => Arr::get($driver, 'driverActivationStatus'),
                'static_vehicle' => Arr::get($driver, 'staticAssignedVehicle.name'),
                'timezone' => Arr::get($driver, 'timezone'),
                'locale' => Arr::get($driver, 'locale'),
                'notes' => Arr::get($driver, 'notes'),
                'tags' => $tagNames,
            ], fn ($value) => $value !== null && $value !== '' && $value !== []),
            'raw' => $driver,
        ];
    }

    /**
     * Map a Samsara vehicle stats record to a normalized location payload.
     *
     * The `gps` field may arrive as a single latest reading (stats endpoint) or
     * as a list of readings; in the latter case the most recent entry is used.
     * Returns null when the record carries no usable coordinates.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null
     */
    private function mapVehicleLocation(array $record): ?array
    {
        $gps = Arr::get($record, 'gps');

        if (is_array($gps) && array_is_list($gps)) {
            $gps = end($gps) ?: null;
        }

        if (! is_array($gps)) {
            return null;
        }

        $latitude = Arr::get($gps, 'latitude');
        $longitude = Arr::get($gps, 'longitude');

        if ($latitude === null || $longitude === null) {
            return null;
        }

        $speed = Arr::get($gps, 'speedMilesPerHour');
        $heading = Arr::get($gps, 'headingDegrees');

        return [
            'external_id' => (string) Arr::get($record, 'id'),
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
            'speed' => self::mphToKph($speed),
            'heading' => $heading !== null ? (int) round((float) $heading) : null,
            'formatted_location' => Arr::get($gps, 'reverseGeo.formattedLocation'),
            'recorded_at' => Arr::get($gps, 'time'),
        ];
    }

    /**
     * Convert a Samsara speed reading to km/h.
     *
     * Every speed Samsara returns is in miles per hour — both `gps
     * .speedMilesPerHour` on the stats endpoints and `location.speed` on
     * `/fleet/vehicles/locations` resolve to the same `VehicleLocationSpeed`
     * schema ("GPS speed of the vehicle in miles per hour"). The domain stores
     * and displays km/h, so the conversion belongs here at the provider
     * boundary rather than in each consumer.
     *
     * Rounded to 2 decimals to match the `decimal(6,2)` storage column.
     */
    private static function mphToKph(mixed $mph): ?float
    {
        if ($mph === null) {
            return null;
        }

        return round((float) $mph * 1.609344, 2);
    }

    private function client(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->baseUrl(rtrim((string) config('services.samsara.base_url'), '/'))
            ->acceptJson()
            ->timeout((int) config('services.samsara.timeout', 15));
    }

    /**
     * Resolve the Samsara API token from the integration's credentials.
     *
     * Looks first at structured IntegrationCredential rows, then falls back to
     * the encrypted credentials blob (JSON or a raw token string).
     */
    private function resolveToken(TenantIntegration $integration): ?string
    {
        $credential = $integration->credentials()
            ->whereIn('key', ['api_token', 'api_key', 'access_token'])
            ->first();

        if ($credential && ! empty($credential->value_encrypted)) {
            return (string) $credential->value_encrypted;
        }

        $raw = $integration->credentials_encrypted;

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                $token = $decoded['api_token'] ?? $decoded['api_key'] ?? $decoded['access_token'] ?? null;

                return $token !== null ? (string) $token : null;
            }

            return $raw;
        }

        return null;
    }
}
