<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Adapters\SamsaraAdapter;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SamsaraAdapterLocationsTest extends TestCase
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
            'status' => 'active',
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

    public function test_it_maps_vehicle_gps_stats_to_normalized_locations(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles/stats*' => Http::response([
                'data' => [
                    [
                        'id' => '100',
                        'gps' => [
                            'latitude' => 40.1234567,
                            'longitude' => -74.7654321,
                            'headingDegrees' => 88.6,
                            'speedMilesPerHour' => 55.5,
                            'time' => '2026-06-08T10:00:00Z',
                            'reverseGeo' => ['formattedLocation' => 'NJ Turnpike'],
                        ],
                    ],
                ],
                'pagination' => ['hasNextPage' => false],
            ], 200),
        ]);

        $locations = app(SamsaraAdapter::class)->fetchAssetLocations($this->makeIntegration());

        $this->assertCount(1, $locations);
        $this->assertSame('100', $locations[0]['external_id']);
        $this->assertEqualsWithDelta(40.1234567, $locations[0]['latitude'], 0.0000001);
        $this->assertEqualsWithDelta(-74.7654321, $locations[0]['longitude'], 0.0000001);
        // Samsara reports `speedMilesPerHour`; the adapter normalizes to km/h
        // so the rest of the domain only ever deals with one unit.
        $this->assertSame(89.32, $locations[0]['speed']);
        $this->assertSame(89, $locations[0]['heading']);
        $this->assertSame('NJ Turnpike', $locations[0]['formatted_location']);
        $this->assertSame('2026-06-08T10:00:00Z', $locations[0]['recorded_at']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'types=gps'));
    }

    public function test_it_skips_records_without_coordinates(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles/stats*' => Http::response([
                'data' => [
                    ['id' => '1', 'gps' => ['latitude' => 10.0, 'longitude' => 20.0]],
                    ['id' => '2', 'gps' => ['headingDegrees' => 10]], // no coordinates
                    ['id' => '3'], // no gps at all
                ],
                'pagination' => ['hasNextPage' => false],
            ], 200),
        ]);

        $locations = app(SamsaraAdapter::class)->fetchAssetLocations($this->makeIntegration());

        $this->assertCount(1, $locations);
        $this->assertSame('1', $locations[0]['external_id']);
    }

    public function test_it_converts_speed_from_mph_to_kph_and_preserves_nulls(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles/stats*' => Http::response([
                'data' => [
                    // 48.3 mph is Samsara's own documented example value.
                    ['id' => '1', 'gps' => ['latitude' => 1.0, 'longitude' => 2.0, 'speedMilesPerHour' => 48.3]],
                    // A parked vehicle reports zero, which must stay zero and
                    // not be confused with "no reading".
                    ['id' => '2', 'gps' => ['latitude' => 1.0, 'longitude' => 2.0, 'speedMilesPerHour' => 0]],
                    // No speed at all: stays null rather than becoming 0 km/h.
                    ['id' => '3', 'gps' => ['latitude' => 1.0, 'longitude' => 2.0]],
                ],
                'pagination' => ['hasNextPage' => false],
            ], 200),
        ]);

        $locations = app(SamsaraAdapter::class)->fetchAssetLocations($this->makeIntegration());

        $this->assertSame(77.73, $locations[0]['speed']);
        $this->assertSame(0.0, $locations[1]['speed']);
        $this->assertNull($locations[2]['speed']);
    }

    public function test_it_returns_empty_without_token(): void
    {
        $this->assertSame([], app(SamsaraAdapter::class)->fetchAssetLocations($this->makeIntegration(token: null)));
    }
}
