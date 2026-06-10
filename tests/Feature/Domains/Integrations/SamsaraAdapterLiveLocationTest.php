<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Adapters\SamsaraAdapter;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SamsaraAdapterLiveLocationTest extends TestCase
{
    use RefreshDatabase;

    private function makeIntegration(?string $token = 'sk-test-token'): TenantIntegration
    {
        $user = User::factory()->create();
        $provider = IntegrationProvider::factory()->samsara()->create();

        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => $user->currentTeam->id,
            'provider_id' => $provider->id,
            'credentials_encrypted' => '',
        ]);

        if ($token !== null) {
            IntegrationCredential::factory()->create([
                'tenant_integration_id' => $integration->id,
                'key' => 'api_token',
                'value_encrypted' => $token,
            ]);
        }

        return $integration->load('provider');
    }

    public function test_it_maps_a_single_vehicle_live_location(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles/locations*' => Http::response([
                'data' => [
                    [
                        'id' => '100',
                        'name' => 'Truck 1',
                        'location' => [
                            'latitude' => 19.4326077,
                            'longitude' => -99.133208,
                            'heading' => 271.5,
                            'speed' => 42.3,
                            'time' => '2026-06-10T01:00:00Z',
                            'reverseGeo' => ['formattedLocation' => 'CDMX Centro'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $live = app(SamsaraAdapter::class)->fetchLiveLocation($this->makeIntegration(), '100');

        $this->assertNotNull($live);
        $this->assertSame('100', $live['external_id']);
        $this->assertEqualsWithDelta(19.4326077, $live['latitude'], 0.0000001);
        $this->assertEqualsWithDelta(-99.133208, $live['longitude'], 0.0000001);
        $this->assertSame(42.3, $live['speed']);
        $this->assertSame(272, $live['heading']);
        $this->assertSame('CDMX Centro', $live['formatted_location']);
        $this->assertSame('2026-06-10T01:00:00Z', $live['recorded_at']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'vehicleIds=100'));
    }

    public function test_it_returns_null_without_token(): void
    {
        Http::fake();

        $live = app(SamsaraAdapter::class)->fetchLiveLocation($this->makeIntegration(token: null), '100');

        $this->assertNull($live);
        Http::assertNothingSent();
    }

    public function test_it_returns_null_on_http_error(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles/locations*' => Http::response(['message' => 'oops'], 500),
        ]);

        $this->assertNull(app(SamsaraAdapter::class)->fetchLiveLocation($this->makeIntegration(), '100'));
    }

    public function test_it_returns_null_on_timeout(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $this->assertNull(app(SamsaraAdapter::class)->fetchLiveLocation($this->makeIntegration(), '100'));
    }

    public function test_it_returns_null_when_response_has_no_coordinates(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles/locations*' => Http::response([
                'data' => [
                    ['id' => '100', 'location' => ['time' => '2026-06-10T01:00:00Z']],
                ],
            ], 200),
        ]);

        $this->assertNull(app(SamsaraAdapter::class)->fetchLiveLocation($this->makeIntegration(), '100'));
    }
}
