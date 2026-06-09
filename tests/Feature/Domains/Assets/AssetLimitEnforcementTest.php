<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Actions\SyncAssetFromIntegration;
use App\Domains\Assets\Exceptions\AssetLimitReachedException;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Tenancy\Enums\FeatureSource;
use App\Domains\Tenancy\Events\UsageLimitExceeded;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AssetLimitEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Team, 1: TenantIntegration}
     */
    private function setupTeam(?int $assetLimit): array
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

        if ($assetLimit !== null) {
            TenantFeature::withoutGlobalScopes()->create([
                'team_id' => $team->id,
                'feature_key' => 'monitored_assets',
                'enabled' => true,
                'source' => FeatureSource::ManualOverride,
                'limits_json' => ['included_quantity' => $assetLimit],
            ]);
        }

        return [$team, $integration];
    }

    public function test_it_blocks_net_new_assets_beyond_the_cap(): void
    {
        Event::fake([UsageLimitExceeded::class]);

        [$team, $integration] = $this->setupTeam(assetLimit: 1);
        $action = app(SyncAssetFromIntegration::class);

        $action->execute($team->id, $integration->id, [
            'external_id' => 'veh-1',
            'name' => 'Truck 1',
            'asset_type_code' => 'vehicle',
        ]);

        $blocked = false;

        try {
            $action->execute($team->id, $integration->id, [
                'external_id' => 'veh-2',
                'name' => 'Truck 2',
                'asset_type_code' => 'vehicle',
            ]);
        } catch (AssetLimitReachedException $e) {
            $blocked = true;
            $this->assertSame($team->id, $e->teamId);
            $this->assertSame(1, $e->limit);
        }

        $this->assertTrue($blocked, 'Second asset should have been blocked by the cap.');
        $this->assertSame(
            1,
            Asset::withoutGlobalScopes()->where('team_id', $team->id)->count(),
        );
        Event::assertDispatched(UsageLimitExceeded::class, fn ($e) => $e->teamId === $team->id
            && $e->meterCode === 'monitored_assets');
    }

    public function test_it_still_updates_existing_assets_when_at_the_cap(): void
    {
        [$team, $integration] = $this->setupTeam(assetLimit: 1);
        $action = app(SyncAssetFromIntegration::class);

        $action->execute($team->id, $integration->id, [
            'external_id' => 'veh-1',
            'name' => 'Original',
            'asset_type_code' => 'vehicle',
        ]);

        // Same external id → update path, must NOT throw even at the cap.
        $updated = $action->execute($team->id, $integration->id, [
            'external_id' => 'veh-1',
            'name' => 'Renamed',
            'asset_type_code' => 'vehicle',
        ]);

        $this->assertSame('Renamed', $updated->name);
        $this->assertSame(
            1,
            Asset::withoutGlobalScopes()->where('team_id', $team->id)->count(),
        );
    }

    public function test_it_allows_unlimited_assets_without_a_cap(): void
    {
        [$team, $integration] = $this->setupTeam(assetLimit: null);
        $action = app(SyncAssetFromIntegration::class);

        foreach (['a', 'b', 'c'] as $ext) {
            $action->execute($team->id, $integration->id, [
                'external_id' => "veh-{$ext}",
                'name' => "Truck {$ext}",
                'asset_type_code' => 'vehicle',
            ]);
        }

        $this->assertSame(
            3,
            Asset::withoutGlobalScopes()->where('team_id', $team->id)->count(),
        );
    }
}
