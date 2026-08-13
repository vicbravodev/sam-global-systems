<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Integrations\Adapters\SamsaraAdapter;
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

    /**
     * @param  array<string, mixed>  $stats
     */
    private function fakeStats(array $stats): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles/stats*' => Http::response([
                'data' => [['id' => '100', 'name' => 'Truck 1'] + $stats],
                'pagination' => ['hasNextPage' => false],
            ], 200),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readingFor(array $readings, TelemetryType $type): ?array
    {
        foreach ($readings as $reading) {
            if ($reading['type'] === $type) {
                return $reading;
            }
        }

        return null;
    }

    public function test_it_splits_requests_to_respect_the_three_type_limit(): void
    {
        $this->fakeStats([]);

        app(SamsaraAdapter::class)->fetchAssetTelemetry($this->makeIntegration());

        // Samsara caps `types` at 3 per request, so the 5 stat types we track
        // have to be fetched across two calls.
        Http::assertSentCount(2);

        Http::assertSent(function ($request) {
            $types = $this->typesOf($request);

            return $types !== null && count($types) <= 3;
        });
    }

    /**
     * @return list<string>|null
     */
    private function typesOf($request): ?array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return isset($query['types']) ? explode(',', (string) $query['types']) : null;
    }

    public function test_it_maps_every_tracked_stat_type_with_normalized_units(): void
    {
        $this->fakeStats([
            'fuelPercents' => ['time' => '2026-08-12T10:00:00Z', 'value' => 54],
            'engineStates' => ['time' => '2026-08-12T10:01:00Z', 'value' => 'On'],
            'obdOdometerMeters' => ['time' => '2026-08-12T10:02:00Z', 'value' => 14010293],
            'batteryMilliVolts' => ['time' => '2026-08-12T10:03:00Z', 'value' => 12640],
            'ambientAirTemperatureMilliC' => ['time' => '2026-08-12T10:04:00Z', 'value' => 31110],
        ]);

        $readings = app(SamsaraAdapter::class)->fetchAssetTelemetry($this->makeIntegration());

        $fuel = $this->readingFor($readings, TelemetryType::Fuel);
        $this->assertSame(54.0, $fuel['value']);
        $this->assertSame('%', $fuel['unit']);
        $this->assertSame('100', $fuel['external_id']);
        $this->assertSame('2026-08-12T10:00:00Z', $fuel['recorded_at']);

        // Engine state is a string enum, not a measurement — no unit.
        $ignition = $this->readingFor($readings, TelemetryType::Ignition);
        $this->assertSame('On', $ignition['value']);
        $this->assertNull($ignition['unit']);

        // 14 010 293 m -> km, one decimal.
        $this->assertSame(14010.3, $this->readingFor($readings, TelemetryType::Odometer)['value']);
        $this->assertSame('km', $this->readingFor($readings, TelemetryType::Odometer)['unit']);

        // 12 640 mV -> V.
        $this->assertSame(12.64, $this->readingFor($readings, TelemetryType::Battery)['value']);
        $this->assertSame('V', $this->readingFor($readings, TelemetryType::Battery)['unit']);

        // 31 110 milli-degrees -> °C.
        $this->assertSame(31.1, $this->readingFor($readings, TelemetryType::Temperature)['value']);
        $this->assertSame('°C', $this->readingFor($readings, TelemetryType::Temperature)['unit']);
    }

    public function test_it_skips_stats_the_vehicle_does_not_report(): void
    {
        // A vehicle with no diagnostic coverage omits obdOdometerMeters
        // entirely rather than sending a null value.
        $this->fakeStats([
            'fuelPercents' => ['time' => '2026-08-12T10:00:00Z', 'value' => 54],
        ]);

        $readings = app(SamsaraAdapter::class)->fetchAssetTelemetry($this->makeIntegration());

        $this->assertCount(1, $readings);
        $this->assertSame(TelemetryType::Fuel, $readings[0]['type']);
    }

    public function test_it_accepts_a_list_of_readings_and_uses_the_most_recent(): void
    {
        $this->fakeStats([
            'fuelPercents' => [
                ['time' => '2026-08-12T09:00:00Z', 'value' => 80],
                ['time' => '2026-08-12T10:00:00Z', 'value' => 54],
            ],
        ]);

        $readings = app(SamsaraAdapter::class)->fetchAssetTelemetry($this->makeIntegration());

        $this->assertSame(54.0, $readings[0]['value']);
        $this->assertSame('2026-08-12T10:00:00Z', $readings[0]['recorded_at']);
    }

    public function test_it_follows_the_pagination_cursor(): void
    {
        $page = 0;

        Http::fake([
            'api.samsara.com/fleet/vehicles/stats*' => function () use (&$page) {
                $page++;

                // First call of each batch paginates once; the cursor must be
                // echoed back as `after`.
                return Http::response([
                    'data' => [[
                        'id' => (string) $page,
                        'fuelPercents' => ['time' => '2026-08-12T10:00:00Z', 'value' => 50],
                    ]],
                    'pagination' => ['endCursor' => 'cursor-'.$page, 'hasNextPage' => $page % 2 === 1],
                ], 200);
            },
        ]);

        $readings = app(SamsaraAdapter::class)->fetchAssetTelemetry($this->makeIntegration());

        $this->assertGreaterThan(1, count($readings));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'after=cursor-1'));
    }

    public function test_it_returns_empty_without_token(): void
    {
        $this->assertSame([], app(SamsaraAdapter::class)->fetchAssetTelemetry($this->makeIntegration(token: null)));
    }

    public function test_it_returns_what_it_has_when_a_batch_fails(): void
    {
        $call = 0;

        Http::fake([
            'api.samsara.com/fleet/vehicles/stats*' => function () use (&$call) {
                $call++;

                if ($call === 1) {
                    return Http::response([
                        'data' => [['id' => '100', 'fuelPercents' => ['time' => '2026-08-12T10:00:00Z', 'value' => 54]]],
                        'pagination' => ['hasNextPage' => false],
                    ], 200);
                }

                return Http::response(['message' => 'rate limited'], 429);
            },
        ]);

        $readings = app(SamsaraAdapter::class)->fetchAssetTelemetry($this->makeIntegration());

        // The successful batch still yields data; the failed one contributes
        // nothing rather than blowing up the whole poll.
        $this->assertCount(1, $readings);
        $this->assertSame(TelemetryType::Fuel, $readings[0]['type']);
    }
}
