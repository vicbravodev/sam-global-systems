<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Drivers\Actions\ResolveHosEnrollment;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverExternalReference;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Integrations\Data\HosClockReading;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveHosEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private TenantIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $team = Team::factory()->create();
        $provider = IntegrationProvider::factory()->samsara()->create();
        $this->integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id, 'provider_id' => $provider->id, 'name' => 'Samsara',
            'status' => 'active', 'auth_type' => 'api_key', 'credentials_encrypted' => '',
        ]);
    }

    private function asset(string $externalId, bool $monitored = true): Asset
    {
        $factory = Asset::factory();
        $asset = ($monitored ? $factory : $factory->pendingMonitoring())->create(['team_id' => $this->integration->team_id]);
        AssetExternalReference::factory()->create(['asset_id' => $asset->id, 'provider_id' => $this->integration->provider_id, 'external_id' => $externalId, 'external_type' => 'vehicle']);

        return $asset;
    }

    private function driver(string $externalId): Driver
    {
        $driver = Driver::factory()->create(['team_id' => $this->integration->team_id]);
        DriverExternalReference::factory()->create(['driver_id' => $driver->id, 'provider_id' => $this->integration->provider_id, 'external_id' => $externalId, 'external_type' => 'driver']);

        return $driver;
    }

    private function reading(string $driverId, ?string $vehicleId): HosClockReading
    {
        return new HosClockReading($driverId, $vehicleId, 'driving', 3600, 3600, 3600, 3600, 0);
    }

    private function config(array $stored): HosMonitoringConfig
    {
        return HosMonitoringConfig::fromArray($stored, config('hos.defaults'));
    }

    private function tags(): array
    {
        return [
            ['id' => '10', 'name' => 'TRACTOS USA', 'parent_id' => null, 'vehicle_ids' => ['v-tag'], 'driver_ids' => []],
            ['id' => '20', 'name' => 'USA', 'parent_id' => null, 'vehicle_ids' => [], 'driver_ids' => ['d-tag']],
            ['id' => '30', 'name' => 'LOCAL JC', 'parent_id' => null, 'vehicle_ids' => [], 'driver_ids' => []],
            ['id' => '31', 'name' => 'LOCAL HT', 'parent_id' => '30', 'vehicle_ids' => ['v-child'], 'driver_ids' => []],
        ];
    }

    public function test_it_enrolls_by_vehicle_tag_driver_tag_child_tag_and_manual_inclusion(): void
    {
        $byVehicleTag = $this->asset('v-tag');
        $this->driver('d1');
        $this->asset('v-plain');
        $byDriverTag = $this->driver('d-tag');
        $this->asset('v-child');
        $this->driver('d3');
        $manual = $this->asset('v-manual');
        $this->driver('d4');
        $this->asset('v-none');
        $this->driver('d5');

        $result = app(ResolveHosEnrollment::class)->execute(
            $this->integration,
            $this->config(['tag_ids' => ['10', '20', '30'], 'included_asset_ids' => [$manual->id]]),
            [
                $this->reading('d1', 'v-tag'),
                $this->reading('d-tag', 'v-plain'),
                $this->reading('d3', 'v-child'),
                $this->reading('d4', 'v-manual'),
                $this->reading('d5', 'v-none'),
            ],
            $this->tags(),
        );

        $this->assertSame(['d1', 'd-tag', 'd3', 'd4'], array_map(fn ($row) => $row['reading']->externalDriverId, $result->enrolled));
        $this->assertSame($byVehicleTag->id, $result->enrolled[0]['asset']->id);
        $this->assertSame($byDriverTag->id, $result->enrolled[1]['driver']->id);
        $this->assertSame(['no_match' => 1], $result->skippedByReason);
    }

    public function test_exclusion_wins_and_unresolvable_rows_are_counted(): void
    {
        $excluded = $this->asset('v-tag');
        $this->driver('d1');
        $this->asset('v-pending', monitored: false);
        $this->driver('d2');
        $this->asset('v-ok');

        $result = app(ResolveHosEnrollment::class)->execute(
            $this->integration,
            $this->config(['tag_ids' => ['10'], 'excluded_asset_ids' => [$excluded->id]]),
            [
                $this->reading('d1', 'v-tag'),
                $this->reading('d2', 'v-pending'),
                $this->reading('d9', 'v-ok'),
                $this->reading('d1', null),
            ],
            $this->tags(),
        );

        $this->assertSame([], $result->enrolled);
        $this->assertSame(['excluded' => 1, 'vehicle_unresolved' => 1, 'driver_unresolved' => 1, 'no_vehicle' => 1], $result->skippedByReason);
    }

    public function test_another_tenants_driver_with_the_same_external_id_is_not_resolved(): void
    {
        $this->asset('v-tag');
        $foreignTeam = Team::factory()->create();
        $foreign = Driver::factory()->create(['team_id' => $foreignTeam->id]);
        DriverExternalReference::factory()->create(['driver_id' => $foreign->id, 'provider_id' => $this->integration->provider_id, 'external_id' => 'd1', 'external_type' => 'driver']);

        $result = app(ResolveHosEnrollment::class)->execute($this->integration, $this->config(['tag_ids' => ['10']]), [$this->reading('d1', 'v-tag')], $this->tags());

        $this->assertSame([], $result->enrolled);
        $this->assertSame(['driver_unresolved' => 1], $result->skippedByReason);
    }
}
