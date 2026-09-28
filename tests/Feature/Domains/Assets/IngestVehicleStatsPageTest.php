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
use Tests\TestCase;

class IngestVehicleStatsPageTest extends TestCase
{
    use RefreshDatabase;

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
}
