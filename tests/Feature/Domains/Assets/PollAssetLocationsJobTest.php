<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Jobs\PollAssetLocationsJob;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PollAssetLocationsJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeSamsaraIntegration(): TenantIntegration
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

        IntegrationCredential::create([
            'tenant_integration_id' => $integration->id,
            'key' => 'api_token',
            'value_encrypted' => 'sk-test',
        ]);

        return $integration->load('provider');
    }

    public function test_it_persists_location_snapshots_for_known_assets_and_skips_unknown(): void
    {
        $integration = $this->makeSamsaraIntegration();

        $asset = Asset::factory()->create(['team_id' => $integration->team_id]);
        AssetExternalReference::create([
            'asset_id' => $asset->id,
            'provider_id' => $integration->provider_id,
            'external_id' => '100',
            'external_type' => 'vehicle',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        Http::fake([
            'api.samsara.com/fleet/vehicles/stats*' => Http::response([
                'data' => [
                    [
                        'id' => '100',
                        'gps' => [
                            'latitude' => 40.1,
                            'longitude' => -74.2,
                            'headingDegrees' => 90,
                            'speedMilesPerHour' => 42.0,
                            'time' => '2026-06-08T09:30:00Z',
                            'reverseGeo' => ['formattedLocation' => 'Exit 8A'],
                        ],
                    ],
                    // Unknown asset — no external reference yet, must be skipped.
                    ['id' => '999', 'gps' => ['latitude' => 1.0, 'longitude' => 2.0]],
                ],
                'pagination' => ['hasNextPage' => false],
            ], 200),
        ]);

        app()->call([new PollAssetLocationsJob($integration), 'handle']);

        $this->assertDatabaseCount('asset_location_snapshots', 1);
        $this->assertDatabaseHas('asset_location_snapshots', [
            'asset_id' => $asset->id,
            'formatted_location' => 'Exit 8A',
            'heading' => 90,
            'source' => 'provider',
        ]);

        $integration->refresh();
        $this->assertNotNull($integration->last_location_poll_at);
    }

    public function test_it_stamps_poll_timestamp_even_when_no_locations_returned(): void
    {
        $integration = $this->makeSamsaraIntegration();

        Http::fake([
            'api.samsara.com/fleet/vehicles/stats*' => Http::response([
                'data' => [],
                'pagination' => ['hasNextPage' => false],
            ], 200),
        ]);

        app()->call([new PollAssetLocationsJob($integration), 'handle']);

        $this->assertDatabaseCount('asset_location_snapshots', 0);
        $integration->refresh();
        $this->assertNotNull($integration->last_location_poll_at);
    }

    public function test_it_targets_the_sync_queue(): void
    {
        $job = new PollAssetLocationsJob(TenantIntegration::factory()->make());

        $this->assertSame('sync', $job->queue);
        $this->assertSame('poll-locations-'.$job->integration->id, $job->uniqueId());
    }
}
