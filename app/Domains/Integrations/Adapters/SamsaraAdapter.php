<?php

namespace App\Domains\Integrations\Adapters;

use App\Contracts\Integrations\MediaRetrievalAdapter;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Models\TenantIntegration;
use Illuminate\Http\Client\PendingRequest;
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

    /**
     * Fetch the latest GPS reading for every vehicle.
     *
     * Uses Samsara's `/fleet/vehicles/stats?types=gps` endpoint, which returns
     * the most recent stat per vehicle. Records without usable coordinates are
     * skipped so the caller only ever receives plottable positions.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAssetLocations(TenantIntegration $integration): array
    {
        $token = $this->resolveToken($integration);

        if ($token === null) {
            return [];
        }

        $locations = [];
        $cursor = null;
        $pages = 0;

        do {
            // /fleet/vehicles/stats has no `limit` param; it returns one row per
            // vehicle and paginates via the `after` cursor.
            $query = ['types' => 'gps'];

            if ($cursor !== null) {
                $query['after'] = $cursor;
            }

            $response = $this->client($token)->get('/fleet/vehicles/stats', $query);

            if (! $response->successful()) {
                break;
            }

            foreach ((array) $response->json('data', []) as $record) {
                $mapped = $this->mapVehicleLocation((array) $record);

                if ($mapped !== null) {
                    $locations[] = $mapped;
                }
            }

            $cursor = $response->json('pagination.endCursor');
            $hasNext = (bool) $response->json('pagination.hasNextPage', false);
            $pages++;
        } while ($hasNext && $cursor && $pages < self::MAX_PAGES);

        return $locations;
    }

    /**
     * Stat types pulled for telemetry, mapped to the telemetry vocabulary the
     * Assets domain stores. All of them live under the same "Read Vehicle
     * Statistics" scope already required for the GPS poll.
     *
     * @var array<string, string>
     */
    private const TELEMETRY_STAT_TYPES = [
        'engineStates' => 'ignition',
        'fuelPercents' => 'fuel',
        'obdOdometerMeters' => 'odometer',
        'batteryMilliVolts' => 'battery',
        'ambientAirTemperatureMilliC' => 'temperature',
    ];

    /**
     * Fetch the latest telemetry reading per vehicle.
     *
     * One `/fleet/vehicles/stats` call covers every type at once (the endpoint
     * returns the most recent value per requested stat), so the whole fleet's
     * telemetry costs a single paginated request. Vehicles whose gateway reports
     * none of the requested stats are omitted rather than returned empty.
     *
     * Speed is deliberately absent: it already rides along with the GPS reading
     * on the location snapshot, and duplicating it here would store the same
     * measurement in two tables.
     *
     * @return array<int, array{external_id: string, readings: array<int, array{type: string, data: array<string, mixed>, recorded_at: string|null}>}>
     */
    public function fetchAssetTelemetry(TenantIntegration $integration): array
    {
        $token = $this->resolveToken($integration);

        if ($token === null) {
            return [];
        }

        $records = [];
        $cursor = null;
        $pages = 0;

        do {
            $query = ['types' => implode(',', array_keys(self::TELEMETRY_STAT_TYPES))];

            if ($cursor !== null) {
                $query['after'] = $cursor;
            }

            $response = $this->client($token)->get('/fleet/vehicles/stats', $query);

            if (! $response->successful()) {
                break;
            }

            foreach ((array) $response->json('data', []) as $record) {
                $mapped = $this->mapVehicleTelemetry((array) $record);

                if ($mapped !== null) {
                    $records[] = $mapped;
                }
            }

            $cursor = $response->json('pagination.endCursor');
            $hasNext = (bool) $response->json('pagination.hasNextPage', false);
            $pages++;
        } while ($hasNext && $cursor && $pages < self::MAX_PAGES);

        return $records;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{external_id: string, readings: array<int, array{type: string, data: array<string, mixed>, recorded_at: string|null}>}|null
     */
    private function mapVehicleTelemetry(array $record): ?array
    {
        $readings = [];

        foreach (self::TELEMETRY_STAT_TYPES as $statType => $telemetryType) {
            $stat = $this->latestStat(Arr::get($record, $statType));

            if ($stat === null) {
                continue;
            }

            $data = $this->mapStatValue($statType, $stat['value']);

            if ($data === null) {
                continue;
            }

            $readings[] = [
                'type' => $telemetryType,
                'data' => $data,
                'recorded_at' => $stat['time'],
            ];
        }

        return $readings === []
            ? null
            : ['external_id' => (string) Arr::get($record, 'id'), 'readings' => $readings];
    }

    /**
     * Normalize a stat field to `{value, time}`. Snapshot requests return one
     * object per type; the history/feed shapes return a list, in which case the
     * most recent entry wins (same handling as the GPS mapping).
     *
     * A reading without a measurement time is dropped: `time` is required on
     * every stat Samsara returns, and without it the caller would have to stamp
     * the reading with the poll time, appending a duplicate row on every tick
     * instead of recognising the reading it already stored.
     *
     * @return array{value: mixed, time: string}|null
     */
    private function latestStat(mixed $stat): ?array
    {
        if (is_array($stat) && array_is_list($stat)) {
            $stat = end($stat) ?: null;
        }

        if (! is_array($stat) || ! array_key_exists('value', $stat) || $stat['value'] === null) {
            return null;
        }

        $time = Arr::get($stat, 'time');

        if (! is_string($time) || $time === '') {
            return null;
        }

        return ['value' => $stat['value'], 'time' => $time];
    }

    /**
     * Convert a raw Samsara stat into the stored `{value, unit}` shape.
     *
     * Units are display-ready: the asset detail panel prints "{value} {unit}"
     * verbatim. Samsara reports in milli-units and meters, which nobody wants to
     * read, so they are converted here rather than at render time.
     *
     * @return array<string, mixed>|null
     */
    private function mapStatValue(string $statType, mixed $value): ?array
    {
        if ($statType === 'engineStates') {
            return is_string($value) && $value !== ''
                ? ['value' => strtolower($value)]
                : null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return match ($statType) {
            'fuelPercents' => ['value' => (float) $value, 'unit' => '%'],
            'obdOdometerMeters' => ['value' => round((float) $value / 1000, 1), 'unit' => 'km'],
            'batteryMilliVolts' => ['value' => round((float) $value / 1000, 2), 'unit' => 'V'],
            'ambientAirTemperatureMilliC' => ['value' => round((float) $value / 1000, 1), 'unit' => '°C'],
            default => null,
        };
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
            'speed' => $speed !== null ? (float) $speed : null,
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
     * `{id}:{eventState}` to let state transitions through. Resumes from the
     * persisted `after` cursor when given one; otherwise starts from
     * `startTime` (the caller's backfill window). Pagination stops at the last
     * page and returns the final `endCursor` so the caller can persist it.
     *
     * @return array{events: array<int, array<string, mixed>>, cursor: string|null}
     */
    public function fetchSafetyEvents(TenantIntegration $integration, ?string $cursor = null, ?\DateTimeInterface $startTime = null): array
    {
        $token = $this->resolveToken($integration);

        if ($token === null) {
            return ['events' => [], 'cursor' => $cursor];
        }

        $events = [];
        $pages = 0;

        do {
            $query = [];

            if ($cursor !== null && $cursor !== '') {
                $query['after'] = $cursor;
            } else {
                $query['startTime'] = Carbon::instance($startTime ?? now()->subDay())->toIso8601String();
            }

            $response = $this->client($token)->get('/safety-events/stream', $query);

            if (! $response->successful()) {
                break;
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
        } while ($hasNext && $cursor && $pages < self::MAX_PAGES);

        return ['events' => $events, 'cursor' => $cursor];
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
                break;
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
        return [
            'external_id' => (string) Arr::get($driver, 'id'),
            'name' => Arr::get($driver, 'name'),
            'username' => Arr::get($driver, 'username'),
            'phone' => Arr::get($driver, 'phone'),
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
            'speed' => $speed !== null ? (float) $speed : null,
            'heading' => $heading !== null ? (int) round((float) $heading) : null,
            'formatted_location' => Arr::get($gps, 'reverseGeo.formattedLocation'),
            'recorded_at' => Arr::get($gps, 'time'),
        ];
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
