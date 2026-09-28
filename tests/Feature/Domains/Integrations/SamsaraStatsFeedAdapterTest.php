<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Assets\Enums\TelematicsFeed;
use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Integrations\Adapters\SamsaraAdapter;
use App\Domains\Integrations\Exceptions\ProviderCursorRejected;
use App\Domains\Integrations\Exceptions\ProviderRateLimited;
use App\Domains\Integrations\Exceptions\ProviderUnauthorized;
use App\Domains\Integrations\Exceptions\ProviderUnavailable;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SamsaraStatsFeedAdapterTest extends TestCase
{
    use RefreshDatabase;

    private function makeIntegration(?string $token = 'sk-test'): TenantIntegration
    {
        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => Team::factory()->create()->id,
            // `code` is unique, so every integration here shares one provider row.
            'provider_id' => (IntegrationProvider::query()->where('code', 'samsara')->first()
                ?? IntegrationProvider::factory()->samsara()->create())->id,
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
     * @param  list<array<string, mixed>>  $data
     */
    private function fakeFeed(array $data, ?string $endCursor = 'cursor-2', bool $hasNextPage = false): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles/stats/*' => Http::response([
                'data' => $data,
                'pagination' => ['endCursor' => $endCursor, 'hasNextPage' => $hasNextPage],
            ], 200),
        ]);
    }

    /**
     * @return list<string>
     */
    private function typesOf(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return explode(',', (string) ($query['types'] ?? ''));
    }

    public function test_each_feed_is_one_request_within_the_three_type_limit(): void
    {
        $this->fakeFeed([]);
        $integration = $this->makeIntegration();

        app(SamsaraAdapter::class)->fetchVehicleStatsFeed($integration, TelematicsFeed::Motion);
        app(SamsaraAdapter::class)->fetchVehicleStatsFeed($integration, TelematicsFeed::Diagnostics);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/fleet/vehicles/stats/feed')
            && $this->typesOf($request) === ['gps', 'engineStates', 'fuelPercents']);
        Http::assertSent(fn (Request $request) => $this->typesOf($request) === ['obdOdometerMeters', 'batteryMilliVolts', 'ambientAirTemperatureMilliC']);
    }

    public function test_the_cursor_is_sent_as_after_and_the_next_one_returned(): void
    {
        $this->fakeFeed([], endCursor: 'cursor-2', hasNextPage: true);

        $page = app(SamsaraAdapter::class)->fetchVehicleStatsFeed($this->makeIntegration(), TelematicsFeed::Motion, 'cursor-1');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'after=cursor-1'));
        $this->assertSame('cursor-2', $page->endCursor);
        $this->assertTrue($page->hasNextPage);
    }

    public function test_it_maps_every_gps_point_of_a_vehicle_with_speed_in_kph(): void
    {
        $this->fakeFeed([[
            'id' => '100',
            'gps' => [
                [
                    'latitude' => 40.1234567,
                    'longitude' => -74.7654321,
                    'headingDegrees' => 88.6,
                    'speedMilesPerHour' => 55.5,
                    'time' => '2026-06-08T10:00:00Z',
                    'reverseGeo' => ['formattedLocation' => 'NJ Turnpike'],
                ],
                // 48.3 mph is Samsara's own documented example value.
                ['latitude' => 40.2, 'longitude' => -74.8, 'speedMilesPerHour' => 48.3, 'time' => '2026-06-08T10:00:05Z'],
                // Parked: zero stays zero, not "no reading".
                ['latitude' => 40.3, 'longitude' => -74.9, 'speedMilesPerHour' => 0, 'time' => '2026-06-08T10:00:10Z'],
                // No speed at all stays null, and no coordinates is skipped.
                ['latitude' => 40.4, 'longitude' => -75.0, 'time' => '2026-06-08T10:00:15Z'],
                ['headingDegrees' => 10, 'time' => '2026-06-08T10:00:20Z'],
            ],
        ]]);

        $locations = app(SamsaraAdapter::class)->fetchVehicleStatsFeed($this->makeIntegration(), TelematicsFeed::Motion)->locations;

        $this->assertCount(4, $locations);
        $this->assertSame('100', $locations[0]['external_id']);
        $this->assertEqualsWithDelta(40.1234567, $locations[0]['latitude'], 0.0000001);
        $this->assertSame(89.32, $locations[0]['speed']);
        $this->assertSame(89, $locations[0]['heading']);
        $this->assertSame('NJ Turnpike', $locations[0]['formatted_location']);
        $this->assertSame('2026-06-08T10:00:00Z', $locations[0]['recorded_at']);
        $this->assertSame(77.73, $locations[1]['speed']);
        $this->assertSame(0.0, $locations[2]['speed']);
        $this->assertNull($locations[3]['speed']);
    }

    public function test_it_maps_every_tracked_stat_with_normalized_units(): void
    {
        $this->fakeFeed([[
            'id' => '100',
            'fuelPercents' => [['time' => '2026-08-12T10:00:00Z', 'value' => 80], ['time' => '2026-08-12T10:05:00Z', 'value' => 54]],
            'engineStates' => [['time' => '2026-08-12T10:01:00Z', 'value' => 'On']],
            'obdOdometerMeters' => [['time' => '2026-08-12T10:02:00Z', 'value' => 14010293]],
            'batteryMilliVolts' => [['time' => '2026-08-12T10:03:00Z', 'value' => 12640]],
            'ambientAirTemperatureMilliC' => [['time' => '2026-08-12T10:04:00Z', 'value' => 31110]],
        ]]);

        $integration = $this->makeIntegration();
        $motion = app(SamsaraAdapter::class)->fetchVehicleStatsFeed($integration, TelematicsFeed::Motion)->readings;
        $diagnostics = app(SamsaraAdapter::class)->fetchVehicleStatsFeed($integration, TelematicsFeed::Diagnostics)->readings;

        $by = fn (array $readings, TelemetryType $type) => array_values(array_filter($readings, fn ($r) => $r['type'] === $type));

        // Every point of the feed is kept, in order: both fuel readings.
        $fuel = $by($motion, TelemetryType::Fuel);
        $this->assertSame([80.0, 54.0], array_column($fuel, 'value'));
        $this->assertSame('%', $fuel[0]['unit']);

        // Engine state is categorical: no unit, kept verbatim.
        $this->assertSame('On', $by($motion, TelemetryType::Ignition)[0]['value']);
        $this->assertNull($by($motion, TelemetryType::Ignition)[0]['unit']);

        $this->assertSame(14010.3, $by($diagnostics, TelemetryType::Odometer)[0]['value']);
        $this->assertSame('km', $by($diagnostics, TelemetryType::Odometer)[0]['unit']);
        $this->assertSame(12.64, $by($diagnostics, TelemetryType::Battery)[0]['value']);
        $this->assertSame('V', $by($diagnostics, TelemetryType::Battery)[0]['unit']);
        $this->assertSame(31.1, $by($diagnostics, TelemetryType::Temperature)[0]['value']);
        $this->assertSame('°C', $by($diagnostics, TelemetryType::Temperature)[0]['unit']);
    }

    public function test_the_history_endpoint_is_queried_for_the_gap_window(): void
    {
        $this->fakeFeed([]);

        app(SamsaraAdapter::class)->fetchVehicleStatsHistory(
            $this->makeIntegration(),
            TelematicsFeed::Motion,
            Carbon::parse('2026-09-27T10:00:00Z'),
            Carbon::parse('2026-09-27T12:00:00Z'),
        );

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/fleet/vehicles/stats/history')
            && $request['startTime'] === '2026-09-27T10:00:00Z'
            && $request['endTime'] === '2026-09-27T12:00:00Z');
    }

    public function test_a_429_becomes_a_rate_limit_with_the_providers_retry_after(): void
    {
        Http::fake(['api.samsara.com/*' => Http::response(['message' => 'slow down'], 429, ['Retry-After' => '0.40235'])]);

        try {
            app(SamsaraAdapter::class)->fetchVehicleStatsFeed($this->makeIntegration(), TelematicsFeed::Motion, 'cursor-1');
            $this->fail('Expected a rate limit.');
        } catch (ProviderRateLimited $e) {
            $this->assertEqualsWithDelta(0.40235, $e->retryAfterSeconds, 0.00001);
        }
    }

    public function test_a_rejected_token_is_unauthorized(): void
    {
        Http::fake(['api.samsara.com/*' => Http::response(['message' => 'bad token'], 401)]);

        $this->expectException(ProviderUnauthorized::class);

        app(SamsaraAdapter::class)->fetchVehicleStatsFeed($this->makeIntegration(), TelematicsFeed::Motion);
    }

    public function test_a_missing_token_is_unauthorized_without_calling_the_provider(): void
    {
        Http::fake();

        $this->expectException(ProviderUnauthorized::class);

        try {
            app(SamsaraAdapter::class)->fetchVehicleStatsFeed($this->makeIntegration(token: null), TelematicsFeed::Motion);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_4xx_on_a_cursored_call_means_the_cursor_was_rejected(): void
    {
        Http::fake(['api.samsara.com/*' => Http::response(['message' => 'invalid cursor'], 400)]);

        $this->expectException(ProviderCursorRejected::class);

        app(SamsaraAdapter::class)->fetchVehicleStatsFeed($this->makeIntegration(), TelematicsFeed::Motion, 'expired');
    }

    public function test_server_errors_and_timeouts_are_transient(): void
    {
        Http::fake(['api.samsara.com/*' => Http::response('oops', 503)]);

        try {
            app(SamsaraAdapter::class)->fetchVehicleStatsFeed($this->makeIntegration(), TelematicsFeed::Motion);
            $this->fail('Expected unavailable.');
        } catch (ProviderUnavailable) {
        }

        Http::fake(['api.samsara.com/*' => fn () => throw new ConnectionException('timed out')]);

        $this->expectException(ProviderUnavailable::class);

        app(SamsaraAdapter::class)->fetchVehicleStatsFeed($this->makeIntegration(), TelematicsFeed::Motion);
    }
}
