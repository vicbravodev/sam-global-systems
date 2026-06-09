<?php

namespace App\Domains\Integrations\Adapters;

use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Models\TenantIntegration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * Real adapter for Samsara's Fleet API (https://developers.samsara.com).
 *
 * - testConnection / sync authenticate with a bearer API token resolved from
 *   the tenant integration's credentials.
 * - validateWebhookSignature verifies Samsara's HMAC-SHA256 signature.
 */
class SamsaraAdapter implements ProviderAdapter
{
    /**
     * Hard cap on pagination pages per sync call to avoid runaway loops.
     */
    private const MAX_PAGES = 50;

    public function testConnection(TenantIntegration $integration): array
    {
        $token = $this->resolveToken($integration);

        if ($token === null) {
            return ['success' => false, 'message' => 'No API token configured for this Samsara integration.'];
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
        return [
            'external_id' => (string) Arr::get($vehicle, 'id'),
            'name' => Arr::get($vehicle, 'name'),
            'vin' => Arr::get($vehicle, 'vin'),
            'license_plate' => Arr::get($vehicle, 'licensePlate'),
            'raw' => $vehicle,
        ];
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
