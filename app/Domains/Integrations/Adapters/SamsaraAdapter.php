<?php

namespace App\Domains\Integrations\Adapters;

use App\Contracts\Integrations\MediaRetrievalAdapter;
use App\Domains\Assets\Enums\TelematicsFeed;
use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Data\HosClockReading;
use App\Domains\Integrations\Data\VehicleStatsPage;
use App\Domains\Integrations\Exceptions\ProviderCursorRejected;
use App\Domains\Integrations\Exceptions\ProviderCursorRejectedException;
use App\Domains\Integrations\Exceptions\ProviderRateLimited;
use App\Domains\Integrations\Exceptions\ProviderRequestFailedException;
use App\Domains\Integrations\Exceptions\ProviderUnauthorized;
use App\Domains\Integrations\Exceptions\ProviderUnavailable;
use App\Domains\Integrations\Jobs\DeprovisionSamsaraWebhookJob;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Normalization\Enums\SamsaraAlertTrigger;
use App\Support\RedactSensitiveLogData;
use App\Support\SafeErrorMessage;
use App\Support\SystemLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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

    /** Tope de páginas al listar media subida (ventana máxima de 1 día). */
    private const UPLOADED_MEDIA_MAX_PAGES = 10;

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

        $input = ['integration_id' => $integration->id, 'team_id' => $integration->team_id];

        if ($token === null) {
            SystemLog::skipped('samsara.test_connection.failed', reason: 'no_token', input: $input);

            return ['success' => false, 'message' => 'No hay token de API configurado para esta integración de Samsara.'];
        }

        try {
            $response = $this->client($token)->get('/fleet/vehicles', ['limit' => 1]);
        } catch (\Throwable $e) {
            SystemLog::degraded('samsara.test_connection.failed', reason: 'connection_failed', input: $input, error: $e);

            return ['success' => false, 'message' => 'Could not reach Samsara: '.SafeErrorMessage::from($e)];
        }

        if ($response->successful()) {
            SystemLog::ok('samsara.test_connection.succeeded', input: $input);

            return ['success' => true, 'message' => 'Connected to Samsara successfully.'];
        }

        $unauthorized = in_array($response->status(), [401, 403], true);

        SystemLog::degraded('samsara.test_connection.failed', reason: $unauthorized ? 'unauthorized' : 'http_error', input: $input + [
            'http_status' => $response->status(),
        ]);

        if ($unauthorized) {
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
            throw new ProviderUnavailable('Could not reach Samsara: '.SafeErrorMessage::from($e), previous: $e);
        }

        $status = $response->status();

        if ($status === 429) {
            // Sin cabecera (o "0") se espera 1 s, como hasta ahora.
            $retryAfter = $response->header('Retry-After');

            throw new ProviderRateLimited(max(0.0, (float) ($retryAfter === '' || $retryAfter === '0' ? 1 : $retryAfter)));
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
            SystemLog::skipped('samsara.gateways.failed', reason: 'no_token', input: ['integration_id' => $integration->id]);

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
                SystemLog::degraded('samsara.gateways.failed', reason: 'http_error', input: [
                    'integration_id' => $integration->id,
                    'http_status' => $response->status(),
                ], calc: ['pages_read' => $pages, 'gateways_discarded' => count($byAsset)]);

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
            // endCursor de Samsara es un token opaco (string no vacío o null).
        } while ($hasNext && is_string($cursor) && $cursor !== '' && $pages < self::MAX_PAGES);

        return array_values($byAsset);
    }

    public function fetchHosClocks(TenantIntegration $integration): array
    {
        $readings = [];

        foreach ($this->fetchAllPages($integration, '/fleet/hos/clocks', 512) as $row) {
            $reading = HosClockReading::fromSamsara($row);

            if ($reading !== null) {
                $readings[] = $reading;
            }
        }

        return $readings;
    }

    public function fetchTags(TenantIntegration $integration): array
    {
        $members = fn (mixed $list): array => array_values(array_filter(array_map(
            fn ($member) => is_array($member) && is_scalar($member['id'] ?? null) ? (string) $member['id'] : null,
            is_array($list) ? $list : [],
        ), fn ($id) => $id !== null && $id !== ''));

        $tags = [];

        foreach ($this->fetchAllPages($integration, '/tags', 512) as $tag) {
            $id = Arr::get($tag, 'id');

            if (! is_scalar($id) || (string) $id === '') {
                continue;
            }

            $parent = Arr::get($tag, 'parentTagId');

            $tags[] = [
                'id' => (string) $id,
                'name' => (string) Arr::get($tag, 'name', ''),
                'parent_id' => is_scalar($parent) && (string) $parent !== '' ? (string) $parent : null,
                'vehicle_ids' => $members(Arr::get($tag, 'vehicles')),
                'driver_ids' => $members(Arr::get($tag, 'drivers')),
            ];
        }

        return $tags;
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
     * Newest reading of a history-style list. Only a non-empty array counts:
     * an empty list, an empty last reading or a scalar reads as "no reading"
     * (callers reject anything that is not an array).
     *
     * @param  array<mixed>  $readings
     * @return array<mixed>|null
     */
    private function newestReading(array $readings): ?array
    {
        $last = end($readings);

        return is_array($last) && $last !== [] ? $last : null;
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
            $stat = $this->newestReading($stat);
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

        $input = ['integration_id' => $integration->id, 'vehicle_id' => $externalAssetId];

        if ($token === null || $externalAssetId === '') {
            SystemLog::skipped('samsara.live_location.failed', reason: $token === null ? 'no_token' : 'no_vehicle_id', input: $input);

            return null;
        }

        $timeout = (int) config('services.samsara.live_location_timeout', 3);

        try {
            $response = $this->client($token)
                ->timeout($timeout)
                ->get('/fleet/vehicles/locations', ['vehicleIds' => $externalAssetId]);
        } catch (\Throwable $e) {
            // Inline en el enriquecimiento de eventos críticos: se degrada a
            // la ubicación guardada (context.live_location.failed lo narra).
            SystemLog::degraded('samsara.live_location.failed', reason: 'connection_failed', input: $input, calc: ['timeout_seconds' => $timeout], error: $e);

            return null;
        }

        if (! $response->successful()) {
            SystemLog::degraded('samsara.live_location.failed', reason: 'http_error', input: $input + ['http_status' => $response->status()]);

            return null;
        }

        $record = (array) ($response->json('data.0') ?? []);
        $location = $record['location'] ?? null;

        if (is_array($location) && array_is_list($location)) {
            $location = $this->newestReading($location);
        }

        if (! is_array($location)) {
            SystemLog::skipped('samsara.live_location.failed', reason: 'no_position', input: $input, calc: ['record_present' => $record !== []]);

            return null;
        }

        $latitude = Arr::get($location, 'latitude');
        $longitude = Arr::get($location, 'longitude');

        if ($latitude === null || $longitude === null) {
            SystemLog::skipped('samsara.live_location.failed', reason: 'no_coordinates', input: $input);

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
     * Enabled alert configurations (`GET /alerts/configurations?status=enabled`)
     * with the trigger type ids each fires on (Panic Button = 1034). Requires
     * the token's "Read Alerts" scope; any non-2xx throws so the caller never
     * mistakes a failure for "no panic alerts configured".
     *
     * @return list<array{id: string, is_enabled: bool, trigger_type_ids: list<int>}>
     *
     * @throws ProviderRequestFailedException
     */
    public function fetchAlertConfigurations(TenantIntegration $integration): array
    {
        $token = $this->resolveToken($integration);

        if ($token === null) {
            return [];
        }

        $configurations = [];
        $cursor = null;
        $pages = 0;

        do {
            $query = ['status' => 'enabled'];

            if ($cursor !== null) {
                $query['after'] = $cursor;
            }

            $response = $this->client($token)->get('/alerts/configurations', $query);

            if (! $response->successful()) {
                throw ProviderRequestFailedException::fromResponse('GET /alerts/configurations', $response);
            }

            foreach ((array) $response->json('data', []) as $record) {
                $record = (array) $record;
                $id = $this->scalarString($record['id'] ?? null);

                if ($id === null || $id === '') {
                    continue;
                }

                $triggerTypeIds = [];

                foreach ((array) ($record['triggers'] ?? []) as $trigger) {
                    $typeId = ((array) $trigger)['triggerTypeId'] ?? null;

                    if (is_numeric($typeId)) {
                        $triggerTypeIds[] = (int) $typeId;
                    }
                }

                $configurations[] = [
                    'id' => $id,
                    'is_enabled' => ($record['isEnabled'] ?? true) !== false,
                    'trigger_type_ids' => $triggerTypeIds,
                ];
            }

            $endCursor = $response->json('pagination.endCursor');
            $cursor = is_string($endCursor) && $endCursor !== '' ? $endCursor : null;
            $hasNext = (bool) $response->json('pagination.hasNextPage', false);
            $pages++;
        } while ($hasNext && $cursor !== null && $pages < self::MAX_PAGES);

        return $configurations;
    }

    /**
     * One page of `GET /alerts/incidents/stream`. `configurationIds` is an
     * exploded array (`configurationIds=a&configurationIds=b`, max 50), so the
     * query string is built by hand: the HTTP client would encode it as
     * `configurationIds[0]=a`. With `after`, `startTime` and the ids must be
     * the ones of the first page (the caller pins them).
     *
     * @param  list<string>  $configurationIds
     * @return array{incidents: list<array<string, mixed>>, cursor: string|null, has_more: bool}
     *
     * @throws ProviderRequestFailedException
     */
    public function fetchAlertIncidents(TenantIntegration $integration, array $configurationIds, string $startTime, ?string $cursor = null): array
    {
        $cursor = $cursor !== null && $cursor !== '' ? $cursor : null;
        $token = $this->resolveToken($integration);

        if ($token === null || $configurationIds === []) {
            return ['incidents' => [], 'cursor' => $cursor, 'has_more' => false];
        }

        $pairs = ['startTime='.rawurlencode($startTime)];

        foreach ($configurationIds as $configurationId) {
            $pairs[] = 'configurationIds='.rawurlencode($configurationId);
        }

        if ($cursor !== null) {
            $pairs[] = 'after='.rawurlencode($cursor);
        }

        $response = $this->client($token)->get('/alerts/incidents/stream?'.implode('&', $pairs));

        if (! $response->successful()) {
            $message = strtolower((string) $response->json('message', ''));
            $cursorRejected = $cursor !== null
                && $response->status() === 400
                && (str_contains($message, 'cursor') || str_contains($message, 'parameters differ'));

            throw $cursorRejected
                ? ProviderCursorRejectedException::fromResponse('GET /alerts/incidents/stream', $response)
                : ProviderRequestFailedException::fromResponse('GET /alerts/incidents/stream', $response);
        }

        $incidents = [];

        foreach ((array) $response->json('data', []) as $record) {
            if (is_array($record)) {
                $incidents[] = $record;
            }
        }

        $endCursor = $response->json('pagination.endCursor');
        $nextCursor = is_string($endCursor) && $endCursor !== '' ? $endCursor : null;
        $hasNext = (bool) $response->json('pagination.hasNextPage', false);

        return [
            'incidents' => $incidents,
            'cursor' => $nextCursor ?? $cursor,
            'has_more' => $hasNext && $nextCursor !== null,
        ];
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
            SystemLog::degraded('samsara.media_retrieval.request_failed', reason: 'connection_failed', input: ['vehicle_id' => $externalAssetId, 'media_type' => $mediaType], error: $e);

            return null;
        }

        if (! $response->successful()) {
            SystemLog::degraded('samsara.media_retrieval.request_failed', reason: 'provider_rejected', input: ['vehicle_id' => $externalAssetId, 'media_type' => $mediaType, 'http_status' => $response->status(), 'provider_message' => Str::limit(RedactSensitiveLogData::sanitize((string) $response->json('message')), 200), 'provider_request_id' => $response->json('requestId')]);

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
            SystemLog::degraded('samsara.media_retrieval.poll_failed', reason: 'connection_failed', input: ['retrieval_id' => $retrievalId], error: $e);

            return ['items' => []];
        }

        if (! $response->successful()) {
            SystemLog::degraded('samsara.media_retrieval.poll_failed', reason: 'provider_rejected', input: ['retrieval_id' => $retrievalId, 'http_status' => $response->status(), 'provider_message' => Str::limit(RedactSensitiveLogData::sanitize((string) $response->json('message')), 200), 'provider_request_id' => $response->json('requestId')]);

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

        $items = [];
        $cursor = null;
        $page = 0;

        // Las fotos periódicas (una por cámara cada ~2 min) llenan la ventana:
        // se siguen los cursores, con tope para que una respuesta rara no
        // encadene peticiones sin fin. Una página que falla corta el listado
        // pero conserva lo ya leído.
        do {
            $query = implode('&', $cursor === null ? $pairs : [...$pairs, 'after='.urlencode($cursor)]);

            try {
                $response = $this->client($token)->get('/cameras/media?'.$query);
            } catch (\Throwable $e) {
                SystemLog::degraded('samsara.uploaded_media.listing_failed', reason: 'connection_failed', input: ['vehicle_id' => $externalAssetId, 'page' => $page + 1], error: $e);

                return ['items' => $items];
            }

            if (! $response->successful()) {
                SystemLog::degraded('samsara.uploaded_media.listing_failed', reason: 'provider_rejected', input: ['vehicle_id' => $externalAssetId, 'page' => $page + 1, 'http_status' => $response->status(), 'provider_message' => Str::limit(RedactSensitiveLogData::sanitize((string) $response->json('message')), 200), 'provider_request_id' => $response->json('requestId')]);

                return ['items' => $items];
            }

            $items = [...$items, ...$this->mapUploadedMedia((array) $response->json('data.media', []))];

            $endCursor = $response->json('pagination.endCursor');
            $cursor = (bool) $response->json('pagination.hasNextPage', false) && is_string($endCursor) && $endCursor !== ''
                ? $endCursor
                : null;
            $page++;
        } while ($cursor !== null && $page < self::UPLOADED_MEDIA_MAX_PAGES);

        return ['items' => $items];
    }

    /**
     * @param  array<int|string, mixed>  $mediaList
     * @return list<array{input: string|null, status: string, url: string|null, media_type: string|null, trigger_reason: string|null, start_time: string|null}>
     */
    private function mapUploadedMedia(array $mediaList): array
    {
        $items = [];

        foreach ($mediaList as $media) {
            $media = (array) $media;
            $url = Arr::get($media, 'urlInfo.url');

            $items[] = [
                'input' => $this->normalizeUploadedInput(Arr::get($media, 'input')),
                'status' => (bool) $url ? 'available' : 'pending',
                'url' => $url,
                // El contrato promete string|null: un escalar se lee como el
                // `(string)` que antes hacía el consumidor; lo demás, ausente.
                'media_type' => $this->scalarString(Arr::get($media, 'mediaType')),
                'trigger_reason' => $this->scalarString(Arr::get($media, 'triggerReason')),
                'start_time' => Arr::get($media, 'startTime'),
            ];
        }

        return $items;
    }

    private function scalarString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
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
     * The timestamp is mandatory: without it there is no freshness to check, and
     * accepting a plain HMAC over the body would let anyone replay a captured
     * signed body forever just by dropping the header. Such requests are
     * rejected (`missing_timestamp`). Both the "v1=" prefixed and raw-hex
     * signature forms are accepted.
     */
    public function validateWebhookSignature(string $payload, string $signature, string $secret, ?string $timestamp = null, ?\DateTimeInterface $receivedAt = null): bool
    {
        $provided = str_starts_with($signature, 'v1=') ? substr($signature, 3) : $signature;
        $hasTimestamp = $timestamp !== null && $timestamp !== '';
        $scheme = $hasTimestamp ? 'timestamped' : 'plain';

        if ($provided === '') {
            SystemLog::degraded('webhook.signature.rejected', reason: 'empty_signature', input: ['scheme' => $scheme]);

            return false;
        }

        if ($timestamp === null || $timestamp === '') {
            SystemLog::degraded('webhook.signature.rejected', reason: 'missing_timestamp', input: ['scheme' => $scheme]);

            return false;
        }

        $check = $this->checkTimestamp($timestamp, $receivedAt);
        $skew = $check['skew_seconds'];
        $tolerance = $check['tolerance_seconds'];

        if (! $check['valid']) {
            SystemLog::degraded('webhook.signature.rejected', reason: 'invalid_timestamp', input: ['scheme' => $scheme]);

            return false;
        }

        if (! $check['within']) {
            SystemLog::degraded('webhook.signature.rejected', reason: 'stale_timestamp', input: ['scheme' => $scheme], calc: [
                'skew_seconds' => $skew,
                'tolerance_seconds' => $tolerance,
                'reference' => $receivedAt !== null ? 'received_at' : 'now',
                'timestamp_unit' => $check['unit'],
            ]);

            return false;
        }

        $message = 'v1:'.$timestamp.':'.$payload;

        $candidates = $this->candidateSecrets($secret);

        foreach ($candidates as $index => $candidate) {
            if (hash_equals(hash_hmac('sha256', $message, $candidate['key']), $provided)) {
                SystemLog::ok('webhook.signature.verified', input: ['scheme' => $scheme], calc: [
                    'secret_variant' => $candidate['variant'],
                    'key_variants_tried' => $index + 1,
                    'skew_seconds' => $skew,
                    'tolerance_seconds' => $tolerance,
                ]);

                return true;
            }
        }

        SystemLog::degraded('webhook.signature.rejected', reason: 'hmac_mismatch', input: ['scheme' => $scheme], calc: [
            'key_variants_tried' => count($candidates),
            'skew_seconds' => $skew,
            'tolerance_seconds' => $tolerance,
        ]);

        return false;
    }

    /**
     * Candidate HMAC keys to try, in priority order.
     *
     * Samsara's Secret Key is Base64-encoded and must be decoded before use, so
     * the decoded bytes are tried first. The raw secret is also tried so callers
     * that store an already-decoded or non-Base64 secret keep working.
     *
     * @return list<array{key: string, variant: string}>
     */
    private function candidateSecrets(string $secret): array
    {
        $candidates = [['key' => $secret, 'variant' => 'raw']];

        $decoded = base64_decode($secret, true);

        if ($decoded !== false && $decoded !== '' && $decoded !== $secret) {
            array_unshift($candidates, ['key' => $decoded, 'variant' => 'base64_decoded']);
        }

        return $candidates;
    }

    /**
     * Reject stale timestamps to protect against replay attacks. A tolerance of
     * 0 (config `services.samsara.webhook_tolerance_seconds`) disables the check.
     *
     * Samsara sends `X-Samsara-Timestamp` in seconds; we defensively also accept
     * a millisecond-precision value.
     */
    /**
     * @return array{within: bool, valid: bool, skew_seconds: ?int, tolerance_seconds: int, unit: ?string}
     */
    private function checkTimestamp(string $timestamp, ?\DateTimeInterface $receivedAt = null): array
    {
        $tolerance = (int) config('services.samsara.webhook_tolerance_seconds', 300);

        if ($tolerance <= 0) {
            return ['within' => true, 'valid' => true, 'skew_seconds' => null, 'tolerance_seconds' => 0, 'unit' => null];
        }

        if (! is_numeric($timestamp)) {
            return ['within' => false, 'valid' => false, 'skew_seconds' => null, 'tolerance_seconds' => $tolerance, 'unit' => null];
        }

        $value = (int) $timestamp;
        // Treat <= 10-digit values as seconds, otherwise milliseconds.
        $isMilliseconds = $value > 9_999_999_999;
        $seconds = $isMilliseconds ? intdiv($value, 1000) : $value;

        // Contra la hora de RECEPCIÓN: una cola atrasada (deploy, pico) no
        // puede convertir pánicos auténticos en "firma inválida".
        $reference = ($receivedAt ?? now())->getTimestamp();
        $skew = abs($reference - $seconds);

        return [
            'within' => $skew <= $tolerance,
            'valid' => true,
            'skew_seconds' => $skew,
            'tolerance_seconds' => $tolerance,
            'unit' => $isMilliseconds ? 'milliseconds' : 'seconds',
        ];
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
            // endCursor de Samsara es un token opaco (string no vacío o null).
        } while ($hasNext && is_string($cursor) && $cursor !== '' && $pages < self::MAX_PAGES);

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
            // Static driver↔vehicle assignment: the driver sync turns it into
            // `driver_assignments` rows. Always present (null = unassigned).
            'static_vehicle_external_id' => $this->scalarString(Arr::get($driver, 'staticAssignedVehicle.id')),
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
            $gps = $this->newestReading($gps);
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

    /**
     * `POST /webhooks` (scope "Write Webhooks"). Without event types: the
     * panic alert created by {@see createPanicAlertConfiguration()} delivers
     * to it through its webhook action. The `secretKey` never reaches a log.
     *
     * @return array{id: string, secret: string}
     */
    public function createWebhook(TenantIntegration $integration, string $name, string $url): array
    {
        $response = $this->writeClient($integration, 'POST /webhooks')->post('/webhooks', [
            'name' => mb_substr($name, 0, 255),
            'url' => $url,
        ]);

        if (! $response->successful()) {
            throw ProviderRequestFailedException::fromResponse('POST /webhooks', $response);
        }

        $id = $this->scalarString($response->json('id'));
        $secret = $this->scalarString($response->json('secretKey'));

        if ($id === null || $id === '' || $secret === null || $secret === '') {
            throw new ProviderRequestFailedException('POST /webhooks', $response->status(), 'La respuesta no trae id ni secretKey.');
        }

        return ['id' => $id, 'secret' => $secret];
    }

    public function deleteWebhook(TenantIntegration $integration, string $webhookId): void
    {
        $endpoint = 'DELETE /webhooks/{id}';
        $response = $this->writeClient($integration, $endpoint)->delete('/webhooks/'.rawurlencode($webhookId));

        if (! $response->successful() && $response->status() !== 404) {
            throw ProviderRequestFailedException::fromResponse($endpoint, $response);
        }
    }

    /**
     * `POST /alerts/configurations` (scope "Write Alerts"): Panic Button
     * (trigger 1034) for every vehicle, delivering to SAM's webhook (action 4).
     */
    public function createPanicAlertConfiguration(TenantIntegration $integration, string $name, string $webhookId): string
    {
        $endpoint = 'POST /alerts/configurations';
        $response = $this->writeClient($integration, $endpoint)->post('/alerts/configurations', [
            'name' => mb_substr($name, 0, 255),
            'isEnabled' => true,
            'scope' => ['all' => true],
            // Samsara exige triggerParams en el 1034 (sin ellos: 400 "invalid
            // trigger"). Sólo pulsaciones: la pérdida de energía del botón no
            // es un pánico y llegaría como falsa emergencia.
            'triggers' => [[
                'triggerTypeId' => SamsaraAlertTrigger::PanicButton->value,
                'triggerParams' => ['panicButton' => ['isFilteringOutPowerLoss' => true]],
            ]],
            'actions' => [self::webhookAction($webhookId)],
        ]);

        if (! $response->successful()) {
            throw ProviderRequestFailedException::fromResponse($endpoint, $response);
        }

        $id = $this->scalarString($response->json('data.id') ?? $response->json('id'));

        if ($id === null || $id === '') {
            throw new ProviderRequestFailedException($endpoint, $response->status(), 'La respuesta no trae el id de la alerta.');
        }

        return $id;
    }

    public function pointAlertConfigurationToWebhook(TenantIntegration $integration, string $configurationId, string $webhookId): void
    {
        $endpoint = 'PATCH /alerts/configurations';
        $response = $this->writeClient($integration, $endpoint)->patch('/alerts/configurations', [
            'id' => $configurationId,
            'actions' => [self::webhookAction($webhookId)],
        ]);

        if (! $response->successful()) {
            throw ProviderRequestFailedException::fromResponse($endpoint, $response);
        }
    }

    public function deleteAlertConfiguration(TenantIntegration $integration, string $configurationId): void
    {
        $endpoint = 'DELETE /alerts/configurations';
        $response = $this->writeClient($integration, $endpoint)->delete('/alerts/configurations?id='.rawurlencode($configurationId));

        if (! $response->successful() && $response->status() !== 404) {
            throw ProviderRequestFailedException::fromResponse($endpoint, $response);
        }
    }

    /**
     * @return array{actionTypeId: int, actionParams: array{webhooks: array{webhookIds: list<string>, payloadType: string}}}
     */
    private static function webhookAction(string $webhookId): array
    {
        return [
            'actionTypeId' => 4,
            'actionParams' => ['webhooks' => ['webhookIds' => [$webhookId], 'payloadType' => 'enriched']],
        ];
    }

    /**
     * Client for the write calls. Without a token there is nothing to send:
     * reported as unauthorized, like a token without the scope.
     */
    private function writeClient(TenantIntegration $integration, string $endpoint): PendingRequest
    {
        $token = $this->resolveToken($integration);

        if ($token === null || $token === '') {
            throw new ProviderRequestFailedException($endpoint, 401, 'No hay token de API configurado para esta integración de Samsara.');
        }

        return $this->client($token);
    }

    private function client(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->baseUrl(rtrim((string) config('services.samsara.base_url'), '/'))
            ->acceptJson()
            ->timeout((int) config('services.samsara.timeout', 15));
    }

    /**
     * The integration's API token, for the one job that must outlive the
     * integration row ({@see DeprovisionSamsaraWebhookJob}, encrypted on the
     * queue). Never log or return it anywhere else.
     */
    public function apiToken(TenantIntegration $integration): ?string
    {
        return $this->resolveToken($integration);
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

        // Igual que el `! empty()` de antes: '' y '0' no son un token y caen
        // al blob de credenciales.
        if ($credential !== null && $credential->value_encrypted !== '' && $credential->value_encrypted !== '0') {
            return $credential->value_encrypted;
        }

        $raw = $integration->credentials_encrypted;

        if ($raw !== '') {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                $token = $decoded['api_token'] ?? $decoded['api_key'] ?? $decoded['access_token'] ?? null;

                return $token !== null ? (string) $token : null;
            }

            return $raw;
        }

        return null;
    }

    /**
     * Every record of a paginated Samsara list, failing typed on any non-2xx
     * or network error (never a partial listing: a missing page would read as data removed).
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllPages(TenantIntegration $integration, string $path, int $limit): array
    {
        $token = $this->resolveToken($integration);

        if ($token === null) {
            throw new ProviderUnauthorized('No hay token de API configurado para esta integración de Samsara.');
        }

        $records = [];
        $cursor = null;
        $pages = 0;

        do {
            $query = ['limit' => $limit];

            if ($cursor !== null) {
                $query['after'] = $cursor;
            }

            try {
                $response = $this->client($token)->get($path, $query);
            } catch (ConnectionException $e) {
                throw new ProviderUnavailable('Could not reach Samsara: '.SafeErrorMessage::from($e), previous: $e);
            }

            $status = $response->status();

            if ($status === 429) {
                $retryAfter = $response->header('Retry-After');

                throw new ProviderRateLimited(max(0.0, (float) ($retryAfter === '' || $retryAfter === '0' ? 1 : $retryAfter)));
            }

            if ($status === 401 || $status === 403) {
                throw new ProviderUnauthorized("Samsara rejected the API token (HTTP {$status}).");
            }

            if ($status >= 500) {
                throw new ProviderUnavailable("Samsara returned HTTP {$status}.");
            }

            if (! $response->successful()) {
                throw ProviderRequestFailedException::fromResponse("GET {$path}", $response);
            }

            foreach ((array) $response->json('data', []) as $record) {
                $records[] = (array) $record;
            }

            $cursor = $response->json('pagination.endCursor');
            $hasNext = (bool) $response->json('pagination.hasNextPage', false);
            $pages++;
            // endCursor de Samsara es un token opaco (string no vacío o null).
        } while ($hasNext && is_string($cursor) && $cursor !== '' && $pages < self::MAX_PAGES);

        // If the loop exited with hasNext still true, we hit the page cap or lost the cursor
        // and couldn't complete the listing — callers must discard this partial result.
        if ($hasNext) {
            throw new ProviderUnavailable("Samsara listing for {$path} was truncated (page cap or missing cursor).");
        }

        return $records;
    }
}
