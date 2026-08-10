<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Adapters\SamsaraAdapter;
use App\Domains\Integrations\Contracts\NullProviderAdapter;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SamsaraAdapterTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private function makeIntegration(?string $token = 'sk-test-token'): TenantIntegration
    {
        $user = User::factory()->create();
        $provider = IntegrationProvider::factory()->samsara()->create();

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $user->currentTeam->id,
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

    /**
     * @param  array<int, array<string, mixed>>  $readings
     * @return array<string, mixed>|null
     */
    private function reading(array $readings, string $type): ?array
    {
        foreach ($readings as $reading) {
            if ($reading['type'] === $type) {
                return $reading;
            }
        }

        return null;
    }

    public function test_it_maps_the_vehicle_stats_snapshot_to_telemetry_readings(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles/stats*' => Http::response([
                'data' => [
                    [
                        'id' => '100',
                        'engineStates' => ['time' => '2026-08-10T09:30:00Z', 'value' => 'Running'],
                        'fuelPercents' => ['time' => '2026-08-10T09:29:00Z', 'value' => 54],
                        'obdOdometerMeters' => ['time' => '2026-08-10T09:28:00Z', 'value' => 14010293],
                        'batteryMilliVolts' => ['time' => '2026-08-10T09:27:00Z', 'value' => 12600],
                        'ambientAirTemperatureMilliC' => ['time' => '2026-08-10T09:26:00Z', 'value' => 31110],
                    ],
                ],
                'pagination' => ['hasNextPage' => false],
            ], 200),
        ]);

        $records = app(SamsaraAdapter::class)->fetchAssetTelemetry($this->makeIntegration());

        $this->assertCount(1, $records);
        $this->assertSame('100', $records[0]['external_id']);

        $readings = $records[0]['readings'];
        $this->assertCount(5, $readings);

        // Units are stored display-ready: the asset detail panel prints
        // "{value} {unit}" verbatim.
        $ignition = $this->reading($readings, 'ignition');
        $this->assertSame('running', $ignition['data']['value']);
        $this->assertSame('2026-08-10T09:30:00Z', $ignition['recorded_at']);

        $fuel = $this->reading($readings, 'fuel');
        $this->assertEquals(54, $fuel['data']['value']);
        $this->assertSame('%', $fuel['data']['unit']);

        // Samsara reports meters; the panel shows kilometres.
        $odometer = $this->reading($readings, 'odometer');
        $this->assertEquals(14010.3, $odometer['data']['value']);
        $this->assertSame('km', $odometer['data']['unit']);

        $battery = $this->reading($readings, 'battery');
        $this->assertEquals(12.6, $battery['data']['value']);
        $this->assertSame('V', $battery['data']['unit']);

        $temperature = $this->reading($readings, 'temperature');
        $this->assertEquals(31.1, $temperature['data']['value']);
        $this->assertSame('°C', $temperature['data']['unit']);
    }

    public function test_it_requests_every_supported_stat_type_in_a_single_call(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles/stats*' => Http::response([
                'data' => [],
                'pagination' => ['hasNextPage' => false],
            ], 200),
        ]);

        app(SamsaraAdapter::class)->fetchAssetTelemetry($this->makeIntegration());

        Http::assertSent(function ($request) {
            $types = explode(',', (string) $request['types']);

            foreach (['engineStates', 'fuelPercents', 'obdOdometerMeters', 'batteryMilliVolts', 'ambientAirTemperatureMilliC'] as $type) {
                if (! in_array($type, $types, true)) {
                    return false;
                }
            }

            return true;
        });

        Http::assertSentCount(1);
    }

    public function test_engine_state_is_normalized_to_a_stable_vocabulary(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles/stats*' => Http::response([
                'data' => [
                    ['id' => '1', 'engineStates' => ['time' => '2026-08-10T09:30:00Z', 'value' => 'Off']],
                    ['id' => '2', 'engineStates' => ['time' => '2026-08-10T09:30:00Z', 'value' => 'Idle']],
                ],
                'pagination' => ['hasNextPage' => false],
            ], 200),
        ]);

        $records = app(SamsaraAdapter::class)->fetchAssetTelemetry($this->makeIntegration());

        $this->assertSame('off', $this->reading($records[0]['readings'], 'ignition')['data']['value']);
        $this->assertSame('idle', $this->reading($records[1]['readings'], 'ignition')['data']['value']);
    }

    public function test_a_stat_reported_as_a_list_uses_the_most_recent_entry(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles/stats*' => Http::response([
                'data' => [
                    [
                        'id' => '100',
                        'fuelPercents' => [
                            ['time' => '2026-08-10T09:00:00Z', 'value' => 80],
                            ['time' => '2026-08-10T09:30:00Z', 'value' => 54],
                        ],
                    ],
                ],
                'pagination' => ['hasNextPage' => false],
            ], 200),
        ]);

        $records = app(SamsaraAdapter::class)->fetchAssetTelemetry($this->makeIntegration());
        $fuel = $this->reading($records[0]['readings'], 'fuel');

        $this->assertEquals(54, $fuel['data']['value']);
        $this->assertSame('2026-08-10T09:30:00Z', $fuel['recorded_at']);
    }

    public function test_a_vehicle_with_no_usable_stats_is_omitted(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles/stats*' => Http::response([
                'data' => [
                    ['id' => '100'],
                    ['id' => '101', 'fuelPercents' => ['time' => '2026-08-10T09:30:00Z', 'value' => null]],
                    ['id' => '102', 'fuelPercents' => ['time' => '2026-08-10T09:30:00Z', 'value' => 20]],
                ],
                'pagination' => ['hasNextPage' => false],
            ], 200),
        ]);

        $records = app(SamsaraAdapter::class)->fetchAssetTelemetry($this->makeIntegration());

        $this->assertCount(1, $records);
        $this->assertSame('102', $records[0]['external_id']);
    }

    public function test_a_reading_without_a_measurement_time_is_dropped(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles/stats*' => Http::response([
                'data' => [
                    [
                        'id' => '100',
                        // No `time`: stamping it with the poll time would append a
                        // duplicate row on every tick instead of matching the
                        // reading already stored.
                        'fuelPercents' => ['value' => 54],
                        'engineStates' => ['time' => '2026-08-10T09:30:00Z', 'value' => 'Running'],
                    ],
                ],
                'pagination' => ['hasNextPage' => false],
            ], 200),
        ]);

        $readings = app(SamsaraAdapter::class)->fetchAssetTelemetry($this->makeIntegration())[0]['readings'];

        $this->assertCount(1, $readings);
        $this->assertSame('ignition', $readings[0]['type']);
    }

    public function test_it_returns_nothing_on_a_provider_error(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles/stats*' => Http::response(['message' => 'forbidden'], 403),
        ]);

        $this->assertSame([], app(SamsaraAdapter::class)->fetchAssetTelemetry($this->makeIntegration()));
    }

    public function test_it_skips_the_provider_without_a_token(): void
    {
        Http::fake();

        $this->assertSame([], app(SamsaraAdapter::class)->fetchAssetTelemetry($this->makeIntegration(token: null)));
        Http::assertNothingSent();
    }

    public function test_the_null_adapter_reports_no_telemetry(): void
    {
        $this->assertSame([], (new NullProviderAdapter)->fetchAssetTelemetry($this->makeIntegration()));
    }
}
