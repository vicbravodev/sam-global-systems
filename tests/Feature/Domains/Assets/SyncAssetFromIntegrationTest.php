<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Actions\ResolveAssetFromExternalId;
use App\Domains\Assets\Actions\SyncAssetFromIntegration;
use App\Domains\Assets\Events\AssetDiscovered;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SyncAssetFromIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function createSetup(): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $provider = IntegrationProvider::factory()->samsara()->create();
        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Test Integration',
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'test-key',
            'status' => 'active',
        ]);

        AssetType::factory()->vehicle()->create();

        return [$user, $team, $provider, $integration];
    }

    public function test_it_creates_asset_from_integration_sync(): void
    {
        Event::fake([AssetDiscovered::class]);

        [, $team, $provider, $integration] = $this->createSetup();

        $action = app(SyncAssetFromIntegration::class);
        $asset = $action->execute($team->id, $integration->id, [
            'external_id' => 'ext-vehicle-001',
            'name' => 'Truck Alpha',
            'asset_type_code' => 'vehicle',
            'code' => 'TRK-001',
        ]);

        $this->assertNotNull(
            $asset->id,
            'Asset should be created and persisted with a valid ID',
        );

        $this->assertEquals(
            'Truck Alpha',
            $asset->name,
            'Asset name should match the data provided by the integration',
        );

        $this->assertEquals(
            $team->id,
            $asset->team_id,
            'Asset should be scoped to the correct team',
        );

        $this->assertTrue(
            AssetExternalReference::where('asset_id', $asset->id)
                ->where('provider_id', $provider->id)
                ->where('external_id', 'ext-vehicle-001')
                ->exists(),
            'External reference should be created linking asset to provider external ID',
        );
    }

    public function test_it_updates_existing_asset_on_duplicate_external_id(): void
    {
        Event::fake([AssetDiscovered::class]);

        [, $team, $provider, $integration] = $this->createSetup();

        $action = app(SyncAssetFromIntegration::class);

        $firstAsset = $action->execute($team->id, $integration->id, [
            'external_id' => 'ext-vehicle-002',
            'name' => 'Original Name',
            'asset_type_code' => 'vehicle',
        ]);

        $updatedAsset = $action->execute($team->id, $integration->id, [
            'external_id' => 'ext-vehicle-002',
            'name' => 'Updated Name',
            'asset_type_code' => 'vehicle',
        ]);

        $this->assertEquals(
            $firstAsset->id,
            $updatedAsset->id,
            'Syncing the same external_id should update the existing asset, not create a new one',
        );

        $this->assertEquals(
            'Updated Name',
            $updatedAsset->name,
            'Asset name should be updated to reflect latest sync data',
        );

        $this->assertEquals(
            1,
            Asset::withoutGlobalScopes()->where('team_id', $team->id)->count(),
            'Only one asset record should exist after duplicate sync',
        );
    }

    public function test_it_dispatches_asset_discovered_event_for_new_asset(): void
    {
        Event::fake([AssetDiscovered::class]);

        [, $team, , $integration] = $this->createSetup();

        $action = app(SyncAssetFromIntegration::class);
        $action->execute($team->id, $integration->id, [
            'external_id' => 'ext-vehicle-003',
            'name' => 'Truck Bravo',
            'asset_type_code' => 'vehicle',
        ]);

        Event::assertDispatched(AssetDiscovered::class, function ($event) use ($team) {
            return $event->teamId === $team->id
                && $event->assetTypeCode === 'vehicle'
                && $event->externalId === 'ext-vehicle-003';
        });

        Event::fake([AssetDiscovered::class]);

        $action->execute($team->id, $integration->id, [
            'external_id' => 'ext-vehicle-003',
            'name' => 'Truck Bravo Updated',
            'asset_type_code' => 'vehicle',
        ]);

        Event::assertNotDispatched(
            AssetDiscovered::class,
            'AssetDiscovered should NOT be dispatched when updating an existing asset',
        );
    }

    public function test_it_resolves_asset_from_external_id_and_provider(): void
    {
        Event::fake([AssetDiscovered::class]);

        [, $team, $provider, $integration] = $this->createSetup();

        $action = app(SyncAssetFromIntegration::class);
        $createdAsset = $action->execute($team->id, $integration->id, [
            'external_id' => 'ext-vehicle-004',
            'name' => 'Tracked Vehicle',
            'asset_type_code' => 'vehicle',
        ]);

        $resolveAction = app(ResolveAssetFromExternalId::class);
        $resolvedAsset = $resolveAction->execute($provider->id, 'ext-vehicle-004');

        $this->assertNotNull(
            $resolvedAsset,
            'ResolveAssetFromExternalId should find the asset by provider and external ID',
        );

        $this->assertEquals(
            $createdAsset->id,
            $resolvedAsset->id,
            'Resolved asset should match the originally created asset',
        );
    }

    public function test_it_returns_null_for_unknown_external_id(): void
    {
        [, , $provider] = $this->createSetup();

        $resolveAction = app(ResolveAssetFromExternalId::class);
        $result = $resolveAction->execute($provider->id, 'nonexistent-id');

        $this->assertNull(
            $result,
            'ResolveAssetFromExternalId should return null for an unknown external ID',
        );
    }
}
