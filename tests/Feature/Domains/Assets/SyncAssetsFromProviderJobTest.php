<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Jobs\SyncAssetsFromProviderJob;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncAssetsFromProviderJobTest extends TestCase
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

    public function test_it_skips_assets_claimed_by_another_tenant_and_finishes_the_batch(): void
    {
        AssetType::factory()->vehicle()->create();

        $owner = $this->makeSamsaraIntegration();
        $ownedAsset = Asset::factory()->create([
            'team_id' => $owner->team_id,
            'name' => 'Tenant A Truck',
        ]);
        AssetExternalReference::create([
            'asset_id' => $ownedAsset->id,
            'provider_id' => $owner->provider_id,
            'external_id' => 'v-1',
            'external_type' => 'vehicle',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $intruder = $this->makeSamsaraIntegration();

        Http::fake([
            'api.samsara.com/fleet/vehicles*' => Http::response([
                'data' => [
                    ['id' => 'v-1', 'name' => 'Hijacked Truck'],
                    ['id' => 'v-2', 'name' => 'Tenant B Truck'],
                ],
                'pagination' => ['hasNextPage' => false],
            ], 200),
            'api.samsara.com/fleet/drivers*' => Http::response([
                'data' => [],
                'pagination' => ['hasNextPage' => false],
            ], 200),
        ]);

        app()->call([new SyncAssetsFromProviderJob($intruder), 'handle']);

        $this->assertEquals(
            'Tenant A Truck',
            $ownedAsset->fresh()->name,
            'A sync run by another tenant must not write over the owning tenant\'s asset',
        );

        $this->assertEquals(
            1,
            Asset::withoutGlobalScopes()->where('team_id', $intruder->team_id)->count(),
            'The colliding vehicle is skipped, but the rest of the batch still syncs',
        );

        $this->assertTrue(
            Asset::withoutGlobalScopes()
                ->where('team_id', $intruder->team_id)
                ->where('external_primary_id', 'v-2')
                ->exists(),
        );
    }
}
