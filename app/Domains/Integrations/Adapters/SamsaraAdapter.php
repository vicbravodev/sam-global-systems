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

    public function validateWebhookSignature(string $payload, string $signature, string $secret): bool
    {
        $expected = hash_hmac('sha256', $payload, $secret);

        // Samsara formats the signature header as "v1=<hex>"; accept the raw
        // hex too so callers can pass either form.
        $provided = str_starts_with($signature, 'v1=') ? substr($signature, 3) : $signature;

        return hash_equals($expected, $provided);
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
