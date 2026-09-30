<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Actions\IngestVehicleStatsPage;
use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Integrations\Data\VehicleStatsPage;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class IngestVehicleStatsPageTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    /**
     * A page arrives every few seconds, so its cost must not grow with the
     * fleet: replaying a fleet that did not move (points already stored, no
     * new readings) takes the same queries for 3 vehicles as for 40.
     */
    public function test_a_page_costs_a_flat_number_of_queries_whatever_the_fleet_size(): void
    {
        $queriesFor = function (int $fleetSize): int {
            $integration = TenantIntegration::factory()->active()->create([
                'team_id' => Team::factory()->create()->id,
                'provider_id' => (IntegrationProvider::query()->where('code', 'samsara')->first()
                    ?? IntegrationProvider::factory()->samsara()->create())->id,
            ]);

            $locations = [];
            $readings = [];

            foreach (range(1, $fleetSize) as $i) {
                $asset = Asset::factory()->create(['team_id' => $integration->team_id]);
                AssetExternalReference::factory()->create([
                    'asset_id' => $asset->id,
                    'provider_id' => $integration->provider_id,
                    'external_id' => "{$fleetSize}-{$i}",
                ]);

                $at = now()->subMinutes(5)->toIso8601ZuluString();
                $locations[] = ['external_id' => "{$fleetSize}-{$i}", 'latitude' => 19.4, 'longitude' => -99.1, 'speed' => 0.0, 'heading' => 0, 'recorded_at' => $at];
                $readings[] = ['external_id' => "{$fleetSize}-{$i}", 'type' => TelemetryType::Fuel, 'value' => 50.0, 'unit' => '%', 'recorded_at' => $at];
            }

            $page = new VehicleStatsPage($locations, $readings, 'c', false);
            app(IngestVehicleStatsPage::class)->execute($integration, $page);

            DB::flushQueryLog();
            DB::enableQueryLog();
            app(IngestVehicleStatsPage::class)->execute($integration, $page);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->assertSame($queriesFor(3), $queriesFor(40));
    }

    public function test_it_counts_every_point_it_drops_by_reason(): void
    {
        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => Team::factory()->create()->id,
            'provider_id' => IntegrationProvider::factory()->samsara()->create()->id,
        ]);
        $asset = Asset::factory()->create(['team_id' => $integration->team_id]);
        AssetExternalReference::factory()->create(['asset_id' => $asset->id, 'provider_id' => $integration->provider_id, 'external_id' => 'v1']);

        $at = now()->subMinutes(5)->toIso8601ZuluString();
        $later = now()->subMinutes(4)->toIso8601ZuluString();

        $page = new VehicleStatsPage(
            locations: [
                ['external_id' => 'v1', 'latitude' => 19.4, 'longitude' => -99.1, 'speed' => 0.0, 'recorded_at' => $at],
                ['external_id' => 'v1', 'latitude' => 19.4, 'longitude' => null, 'recorded_at' => $at],
                ['external_id' => 'ghost', 'latitude' => 19.4, 'longitude' => -99.1, 'recorded_at' => $at],
                ['external_id' => '', 'latitude' => 19.4, 'longitude' => -99.1, 'recorded_at' => $at],
            ],
            readings: [
                ['external_id' => 'v1', 'type' => TelemetryType::Fuel, 'value' => 50.0, 'unit' => '%', 'recorded_at' => $at],
                ['external_id' => 'v1', 'type' => TelemetryType::Fuel, 'value' => 50.0, 'unit' => '%', 'recorded_at' => $later],
                ['external_id' => 'v1', 'type' => 'fuel', 'value' => 50.0, 'recorded_at' => $at],
                ['external_id' => 'v1', 'type' => TelemetryType::Fuel, 'value' => null, 'recorded_at' => $at],
                ['external_id' => 'ghost', 'type' => TelemetryType::Fuel, 'value' => 10.0, 'recorded_at' => $at],
                ['external_id' => '', 'type' => TelemetryType::Fuel, 'value' => 10.0, 'recorded_at' => $at],
            ],
            endCursor: 'c',
            hasNextPage: false,
        );

        $first = app(IngestVehicleStatsPage::class)->execute($integration, $page);

        $this->assertSame(1, $first->locationsStored);
        $this->assertSame(1, $first->readingsStored);
        $this->assertEquals([
            'no_external_id' => 2,
            'unknown_vehicle' => 2,
            'missing_coordinates' => 1,
            'unsupported_type' => 1,
            'missing_value' => 1,
            'unchanged_value' => 1,
        ], array_filter($first->dropped));

        // Replayed: the stored point is ignored by the unique index; the
        // stored reading is no longer news.
        $second = app(IngestVehicleStatsPage::class)->execute($integration, $page);
        $this->assertSame(1, $second->dropped['already_stored']);
        $this->assertSame(2, $second->dropped['unchanged_value']);

        // Counters add up page over page.
        $this->assertSame(4, $first->merge($second)->dropped['no_external_id']);
    }

    public function test_the_early_returns_still_count_what_they_drop(): void
    {
        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => Team::factory()->create()->id,
            'provider_id' => IntegrationProvider::factory()->samsara()->create()->id,
        ]);
        $at = now()->toIso8601ZuluString();

        $noIds = app(IngestVehicleStatsPage::class)->execute($integration, new VehicleStatsPage(
            [['external_id' => '', 'latitude' => 1.0, 'longitude' => 1.0, 'recorded_at' => $at]],
            [['external_id' => '', 'type' => TelemetryType::Fuel, 'value' => 1.0, 'recorded_at' => $at]],
            null,
            false,
        ));
        $this->assertSame(['no_external_id' => 2], array_filter($noIds->dropped));

        $unknown = app(IngestVehicleStatsPage::class)->execute($integration, new VehicleStatsPage(
            [['external_id' => 'x', 'latitude' => 1.0, 'longitude' => 1.0, 'recorded_at' => $at], ['external_id' => '', 'latitude' => 1.0, 'longitude' => 1.0, 'recorded_at' => $at]],
            [['external_id' => 'y', 'type' => TelemetryType::Fuel, 'value' => 1.0, 'recorded_at' => $at]],
            null,
            false,
        ));
        $this->assertEquals(['no_external_id' => 1, 'unknown_vehicle' => 2], array_filter($unknown->dropped));
        $this->assertNoSensitiveDataLogged();
    }
}
