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
        $this->assertStringContainsString('No API token', $result['message']);
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
