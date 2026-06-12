<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Adapters\ProviderAdapterManager;
use App\Domains\Integrations\Adapters\SamsaraAdapter;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SamsaraAdapterTest extends TestCase
{
    use RefreshDatabase;

    private function makeIntegration(?string $token = 'sk-test-token'): TenantIntegration
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->samsara()->create();

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Samsara Fleet',
            'status' => 'pending',
            'auth_type' => 'api_key',
            'credentials_encrypted' => '',
        ]);

        if ($token !== null) {
            IntegrationCredential::create([
                'tenant_integration_id' => $integration->id,
                'key' => 'api_token',
                'value_encrypted' => $token,
            ]);
        }

        return $integration->load('provider');
    }

    public function test_test_connection_succeeds_with_valid_token(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles*' => Http::response(['data' => [['id' => '1']]], 200),
        ]);

        $result = app(SamsaraAdapter::class)->testConnection($this->makeIntegration());

        $this->assertTrue($result['success']);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-test-token'));
    }

    public function test_test_connection_reports_rejected_token(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles*' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $result = app(SamsaraAdapter::class)->testConnection($this->makeIntegration());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('401', $result['message']);
    }

    public function test_test_connection_fails_without_token(): void
    {
        $result = app(SamsaraAdapter::class)->testConnection($this->makeIntegration(token: null));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No hay token de API', $result['message']);
    }

    public function test_sync_maps_vehicles_and_drivers(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles*' => Http::response([
                'data' => [['id' => '100', 'name' => 'Truck 1', 'vin' => 'VIN100']],
                'pagination' => ['hasNextPage' => false],
            ], 200),
            'api.samsara.com/fleet/drivers*' => Http::response([
                'data' => [['id' => '200', 'name' => 'Jane Doe', 'username' => 'jane']],
                'pagination' => ['hasNextPage' => false],
            ], 200),
        ]);

        $result = app(SamsaraAdapter::class)->sync($this->makeIntegration(), 'full');

        $this->assertCount(1, $result['assets']);
        $this->assertSame('100', $result['assets'][0]['external_id']);
        $this->assertSame('VIN100', $result['assets'][0]['vin']);
        $this->assertCount(1, $result['drivers']);
        $this->assertSame('200', $result['drivers'][0]['external_id']);
        $this->assertSame(2, $result['records_processed']);
        $this->assertSame([], $result['events']);
    }

    public function test_validate_webhook_signature_accepts_v1_and_raw_forms(): void
    {
        $adapter = app(SamsaraAdapter::class);
        $payload = '{"event":"test"}';
        $secret = 'whsec';
        $hmac = hash_hmac('sha256', $payload, $secret);

        $this->assertTrue($adapter->validateWebhookSignature($payload, $hmac, $secret));
        $this->assertTrue($adapter->validateWebhookSignature($payload, 'v1='.$hmac, $secret));
        $this->assertFalse($adapter->validateWebhookSignature($payload, 'deadbeef', $secret));
        $this->assertFalse($adapter->validateWebhookSignature($payload, '', $secret));
    }

    public function test_validate_webhook_signature_verifies_real_samsara_scheme(): void
    {
        $adapter = app(SamsaraAdapter::class);
        $payload = '{"eventId":"abc","eventType":"AlertIncident"}';
        $secret = 'whsec';
        $timestamp = (string) now()->getTimestampMs();

        // Samsara signs "v1:{timestamp}:{rawBody}" and ships it as "v1=<hmac>".
        $hmac = hash_hmac('sha256', 'v1:'.$timestamp.':'.$payload, $secret);

        $this->assertTrue($adapter->validateWebhookSignature($payload, 'v1='.$hmac, $secret, $timestamp));
        $this->assertTrue($adapter->validateWebhookSignature($payload, $hmac, $secret, $timestamp));

        // A signature computed without the timestamp must not validate the timestamped message.
        $plain = hash_hmac('sha256', $payload, $secret);
        $this->assertFalse($adapter->validateWebhookSignature($payload, 'v1='.$plain, $secret, $timestamp));
    }

    public function test_validate_webhook_signature_decodes_base64_secret_key(): void
    {
        $adapter = app(SamsaraAdapter::class);
        $payload = '{"eventId":"abc","eventType":"AlertIncident"}';
        // Samsara's dashboard Secret Key is Base64; the HMAC key is its decoded bytes.
        $rawKey = random_bytes(16);
        $storedSecret = base64_encode($rawKey);
        // Samsara sends X-Samsara-Timestamp in seconds.
        $timestamp = (string) now()->getTimestamp();

        $hmac = hash_hmac('sha256', 'v1:'.$timestamp.':'.$payload, $rawKey);

        $this->assertTrue(
            $adapter->validateWebhookSignature($payload, 'v1='.$hmac, $storedSecret, $timestamp),
            'A signature computed over the Base64-decoded Secret Key must validate.',
        );

        // A signature computed over the raw (still-encoded) secret must NOT validate
        // against the decoded key — confirming we use the decoded bytes.
        $wrong = hash_hmac('sha256', 'v1:'.$timestamp.':'.$payload, 'not-the-key');
        $this->assertFalse($adapter->validateWebhookSignature($payload, 'v1='.$wrong, $storedSecret, $timestamp));
    }

    public function test_validate_webhook_signature_rejects_stale_timestamp(): void
    {
        config()->set('services.samsara.webhook_tolerance_seconds', 300);

        $adapter = app(SamsaraAdapter::class);
        $payload = '{"eventId":"abc"}';
        $secret = 'whsec';
        // 10 minutes old (in ms) — outside the 5-minute tolerance.
        $timestamp = (string) (now()->getTimestampMs() - 600_000);
        $hmac = hash_hmac('sha256', 'v1:'.$timestamp.':'.$payload, $secret);

        $this->assertFalse($adapter->validateWebhookSignature($payload, 'v1='.$hmac, $secret, $timestamp));

        // With the replay check disabled the same (otherwise valid) signature passes.
        config()->set('services.samsara.webhook_tolerance_seconds', 0);
        $this->assertTrue($adapter->validateWebhookSignature($payload, 'v1='.$hmac, $secret, $timestamp));
    }

    public function test_list_uploaded_media_maps_items_and_normalizes_inputs(): void
    {
        Http::fake([
            'api.samsara.com/cameras/media?*' => Http::response([
                'data' => ['media' => [
                    [
                        'input' => 'dashcamForwardFacing',
                        'mediaType' => 'videoHighRes',
                        'triggerReason' => 'panicButton',
                        'startTime' => '2026-06-07T01:29:35Z',
                        'endTime' => '2026-06-07T01:30:35Z',
                        'urlInfo' => ['url' => 'https://media.samsara.com/panic.mp4'],
                        'vehicleId' => '281474993032573',
                        'availableAtTime' => '2026-06-07T01:31:00Z',
                    ],
                    [
                        'input' => 'dashcamInwardFacing',
                        'mediaType' => 'image',
                        'triggerReason' => 'panicButton',
                        'startTime' => '2026-06-07T01:29:40Z',
                        'endTime' => '2026-06-07T01:29:40Z',
                        'vehicleId' => '281474993032573',
                        'availableAtTime' => '2026-06-07T01:31:00Z',
                    ],
                ]],
                'pagination' => ['endCursor' => '', 'hasNextPage' => false],
            ]),
        ]);

        $items = app(SamsaraAdapter::class)->listUploadedMedia(
            $this->makeIntegration(),
            '281474993032573',
            new \DateTimeImmutable('2026-06-07T01:00:00Z'),
            new \DateTimeImmutable('2026-06-07T02:00:00Z'),
            ['panicButton', 'safetyEvent'],
        )['items'];

        $this->assertCount(2, $items);

        // Uploaded-media inputs come in the forward/inward vocabulary and are
        // normalized to the retrieval names used by the rest of the pipeline.
        $this->assertSame('dashcamRoadFacing', $items[0]['input']);
        $this->assertSame('available', $items[0]['status']);
        $this->assertSame('https://media.samsara.com/panic.mp4', $items[0]['url']);
        $this->assertSame('videoHighRes', $items[0]['media_type']);
        $this->assertSame('panicButton', $items[0]['trigger_reason']);
        $this->assertSame('2026-06-07T01:29:35Z', $items[0]['start_time']);

        // No urlInfo yet → pending, never a downloadable item.
        $this->assertSame('dashcamDriverFacing', $items[1]['input']);
        $this->assertSame('pending', $items[1]['status']);
        $this->assertNull($items[1]['url']);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && $request['vehicleIds'] === '281474993032573'
                && $request['triggerReasons'] === 'panicButton,safetyEvent'
                && isset($request['startTime'], $request['endTime']);
        });
    }

    public function test_list_uploaded_media_returns_empty_items_on_provider_error(): void
    {
        Http::fake([
            'api.samsara.com/cameras/media?*' => Http::response(['message' => 'forbidden'], 403),
        ]);

        $result = app(SamsaraAdapter::class)->listUploadedMedia(
            $this->makeIntegration(),
            'veh-1',
            new \DateTimeImmutable('2026-06-07T01:00:00Z'),
            new \DateTimeImmutable('2026-06-07T02:00:00Z'),
        );

        $this->assertSame(['items' => []], $result);
    }

    public function test_list_uploaded_media_without_token_skips_the_provider(): void
    {
        Http::fake();

        $result = app(SamsaraAdapter::class)->listUploadedMedia(
            $this->makeIntegration(token: null),
            'veh-1',
            new \DateTimeImmutable('2026-06-07T01:00:00Z'),
            new \DateTimeImmutable('2026-06-07T02:00:00Z'),
        );

        $this->assertSame(['items' => []], $result);
        Http::assertNothingSent();
    }

    public function test_manager_routes_samsara_provider_to_samsara_adapter(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles*' => Http::response(['data' => []], 200),
        ]);

        $manager = app(ProviderAdapter::class);
        $this->assertInstanceOf(ProviderAdapterManager::class, $manager);

        $result = $manager->testConnection($this->makeIntegration());

        $this->assertTrue($result['success']);
    }

    public function test_manager_routes_unknown_provider_to_null_adapter(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->create(['code' => 'unknown-provider']);

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Other',
            'status' => 'pending',
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'x',
        ])->load('provider');

        $result = app(ProviderAdapter::class)->sync($integration, 'full');

        // Null adapter returns empty buckets without hitting any HTTP API.
        $this->assertSame(0, $result['records_processed']);
    }
}
