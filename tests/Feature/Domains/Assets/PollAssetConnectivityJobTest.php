<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Jobs\PollAssetConnectivityJob;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class PollAssetConnectivityJobTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

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

    /**
     * @param  array<int, array<string, mixed>>  $gateways
     */
    private function fakeGateways(array $gateways): void
    {
        Http::fake([
            'api.samsara.com/gateways*' => Http::response([
                'data' => $gateways,
                'pagination' => ['endCursor' => '', 'hasNextPage' => false],
            ], 200),
        ]);
    }

    public function test_it_stores_the_device_heartbeat_on_known_assets(): void
    {
        $integration = $this->makeSamsaraIntegration();
        $asset = $this->linkAsset($integration, '100');

        $this->fakeGateways([
            [
                'serial' => 'GH3D-VU5-F42',
                'model' => 'VG55NA',
                'asset' => ['id' => '100'],
                'connectionStatus' => ['healthStatus' => 'Connected', 'lastConnected' => '2026-09-28T00:17:20.316Z'],
            ],
            // Unknown asset: skipped.
            ['serial' => 'X', 'model' => 'VG55NA', 'asset' => ['id' => '555'], 'connectionStatus' => ['lastConnected' => '2026-09-28T00:00:00Z']],
        ]);

        app()->call([new PollAssetConnectivityJob($integration), 'handle']);

        $fresh = $asset->fresh();
        $this->assertSame('2026-09-28T00:17:20+00:00', $fresh->device_last_connected_at->toIso8601String());
        $this->assertSame('Connected', $fresh->device_health_status);
        $this->assertNotNull($fresh->device_connectivity_polled_at);
    }

    public function test_the_telematics_gateway_represents_the_asset_over_its_dashcam(): void
    {
        $integration = $this->makeSamsaraIntegration();
        $asset = $this->linkAsset($integration, '100');

        $this->fakeGateways([
            ['serial' => 'CAM', 'model' => 'CM34', 'asset' => ['id' => '100'], 'connectionStatus' => ['healthStatus' => 'Connected', 'lastConnected' => '2026-09-28T00:20:00Z']],
            ['serial' => 'VG', 'model' => 'VG55NA', 'asset' => ['id' => '100'], 'connectionStatus' => ['healthStatus' => 'Unplugged', 'lastConnected' => '2026-09-27T22:00:00Z']],
        ]);

        app()->call([new PollAssetConnectivityJob($integration), 'handle']);

        $fresh = $asset->fresh();
        $this->assertSame('Unplugged', $fresh->device_health_status);
        $this->assertSame('2026-09-27T22:00:00+00:00', $fresh->device_last_connected_at->toIso8601String());
    }

    public function test_a_failed_listing_leaves_the_last_good_reading_untouched(): void
    {
        $integration = $this->makeSamsaraIntegration();
        $asset = $this->linkAsset($integration, '100');
        $asset->forceFill(['device_connectivity_polled_at' => now()->subMinutes(30)])->save();

        Http::fake(['api.samsara.com/gateways*' => Http::response(['message' => 'Exceeded rate limit.'], 429)]);

        app()->call([new PollAssetConnectivityJob($integration), 'handle']);

        // Not re-stamped: the watchdog sees the reading as stale and stays quiet.
        $this->assertTrue($asset->fresh()->device_connectivity_polled_at->lt(now()->subMinutes(29)));
    }

    public function test_it_never_writes_connectivity_onto_another_tenants_asset(): void
    {
        $first = $this->makeSamsaraIntegration();
        $firstAsset = $this->linkAsset($first, '100');

        $second = $this->makeSamsaraIntegration();
        $this->linkAsset($second, '999');

        $this->fakeGateways([
            ['serial' => 'A', 'model' => 'VG55NA', 'asset' => ['id' => '100'], 'connectionStatus' => ['lastConnected' => '2026-09-28T00:00:00Z']],
            ['serial' => 'B', 'model' => 'VG55NA', 'asset' => ['id' => '999'], 'connectionStatus' => ['lastConnected' => '2026-09-28T00:00:00Z']],
        ]);

        $this->assertNoTenantLeak($first->team_id, fn () => app()->call([new PollAssetConnectivityJob($first), 'handle']));

        $this->assertNotNull($firstAsset->fresh()->device_last_connected_at);
    }

    public function test_it_targets_the_sync_queue(): void
    {
        $job = new PollAssetConnectivityJob(TenantIntegration::factory()->make());

        $this->assertSame('sync', $job->queue);
        $this->assertSame('poll-connectivity-'.$job->integration->id, $job->uniqueId());
    }
}
