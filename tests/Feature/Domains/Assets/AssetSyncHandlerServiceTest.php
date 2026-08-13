<?php

namespace Tests\Feature\Domains\Assets;

use App\Contracts\AssetSyncHandler;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetSyncHandlerServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeIntegration(): TenantIntegration
    {
        $user = User::factory()->create();
        // `code` is unique, so tests with two tenants share one provider row.
        $provider = IntegrationProvider::where('code', 'samsara')->first()
            ?? IntegrationProvider::factory()->samsara()->create();

        return TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $user->currentTeam->id,
            'provider_id' => $provider->id,
            'name' => 'Samsara Fleet',
            'status' => 'active',
            'auth_type' => 'api_key',
            'credentials_encrypted' => '',
        ]);
    }

    public function test_it_creates_the_asset_for_its_own_tenant(): void
    {
        AssetType::factory()->vehicle()->create();

        $integration = $this->makeIntegration();

        app(AssetSyncHandler::class)->syncFromIntegration(
            $integration->team_id,
            $integration->id,
            ['external_id' => 'v-10', 'name' => 'Truck', 'asset_type_code' => 'vehicle'],
        );

        $this->assertTrue(
            Asset::withoutGlobalScopes()
                ->where('team_id', $integration->team_id)
                ->where('external_primary_id', 'v-10')
                ->exists(),
        );
    }

    public function test_it_swallows_a_cross_tenant_external_id_collision(): void
    {
        AssetType::factory()->vehicle()->create();

        $owner = $this->makeIntegration();
        $ownedAsset = Asset::factory()->create([
            'team_id' => $owner->team_id,
            'name' => 'Tenant A Truck',
        ]);
        AssetExternalReference::create([
            'asset_id' => $ownedAsset->id,
            'provider_id' => $owner->provider_id,
            'external_id' => 'v-11',
            'external_type' => 'vehicle',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $intruder = $this->makeIntegration();

        // Ingestion drives this handler one event at a time: a claimed external
        // id must be skipped, not blow up the event that carried it.
        app(AssetSyncHandler::class)->syncFromIntegration(
            $intruder->team_id,
            $intruder->id,
            ['external_id' => 'v-11', 'name' => 'Hijacked Truck', 'asset_type_code' => 'vehicle'],
        );

        $this->assertEquals('Tenant A Truck', $ownedAsset->fresh()->name);
        $this->assertEquals(
            0,
            Asset::withoutGlobalScopes()->where('team_id', $intruder->team_id)->count(),
        );
    }
}
