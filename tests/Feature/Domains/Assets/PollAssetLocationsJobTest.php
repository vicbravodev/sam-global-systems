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
        // `code` is unique, so tests with two tenants share one provider row.
        $provider = IntegrationProvider::where('code', 'samsara')->first()
            ?? IntegrationProvider::factory()->samsara()->create();

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

    private function linkAsset(TenantIntegration $integration, string $externalId): Asset
    {
        $asset = Asset::factory()->create(['team_id' => $integration->team_id]);

        AssetExternalReference::create([
            'asset_id' => $asset->id,
            'provider_id' => $integration->provider_id,
            'external_id' => $externalId,
            'external_type' => 'vehicle',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        return $asset;
    }

    public function test_it_persists_location_snapshots_for_known_assets_and_skips_unknown(): void
    {
        $integration = $this->makeSamsaraIntegration();

        $asset = $this->linkAsset($integration, '100');

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

    public function test_it_never_writes_locations_onto_another_tenants_asset(): void
    {
        $first = $this->makeSamsaraIntegration();
        $firstAsset = $this->linkAsset($first, '100');

        // `asset_external_references` is unique on (provider_id, external_id)
        // globally, so the other tenant owns a different id — and the resolver
        // looks the reference up across every tenant.
        $second = $this->makeSamsaraIntegration();
        $otherTenantAsset = $this->linkAsset($second, '999');

        Http::fake([
            'api.samsara.com/fleet/vehicles/stats*' => Http::response([
                'data' => [
                    ['id' => '100', 'gps' => ['latitude' => 40.1, 'longitude' => -74.2]],
                    ['id' => '999', 'gps' => ['latitude' => 1.0, 'longitude' => 2.0]],
                ],
                'pagination' => ['hasNextPage' => false],
            ], 200),
        ]);

        app()->call([new PollAssetLocationsJob($first), 'handle']);

        // A poll for one tenant must never write rows against another tenant's asset.
        $this->assertDatabaseCount('asset_location_snapshots', 1);
        $this->assertDatabaseHas('asset_location_snapshots', ['asset_id' => $firstAsset->id]);
        $this->assertDatabaseMissing('asset_location_snapshots', ['asset_id' => $otherTenantAsset->id]);
    }

    public function test_it_targets_the_sync_queue(): void
    {
        $job = new PollAssetLocationsJob(TenantIntegration::factory()->make());

        $this->assertSame('sync', $job->queue);
        $this->assertSame('poll-locations-'.$job->integration->id, $job->uniqueId());
    }
}
