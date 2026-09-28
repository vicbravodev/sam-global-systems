<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Actions\SetAssetMonitoring;
use App\Domains\Assets\Actions\SyncAssetFromIntegration;
use App\Domains\Assets\Enums\AssetMonitoringState;
use App\Domains\Assets\Events\AssetMonitoringChanged;
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
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Vigilancia por activo (decisión 2026-09-28): el sync descubre toda la
 * flota como `pending`, el cliente enciende lo que quiere y el tope es
 * SUAVE — encender de más se permite, se avisa y se cobra como extra.
 */
class AssetMonitoringTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

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

    public function test_sync_discovers_every_asset_as_pending_regardless_of_the_cap(): void
    {
        [$team, $integration] = $this->setupTeam(assetLimit: 1);
        $action = app(SyncAssetFromIntegration::class);

        foreach (['a', 'b', 'c'] as $ext) {
            $action->execute($team->id, $integration->id, [
                'external_id' => "veh-{$ext}",
                'name' => "Truck {$ext}",
                'asset_type_code' => 'vehicle',
            ]);
        }

        $assets = Asset::withoutGlobalScopes()->where('team_id', $team->id)->get();

        $this->assertCount(3, $assets, 'The cap never hides fleet units from the inventory');
        $this->assertTrue($assets->every(fn (Asset $asset) => $asset->monitoring_state === AssetMonitoringState::Pending));
    }

    public function test_switching_on_within_the_cap_does_not_flag_overage(): void
    {
        Event::fake([UsageLimitExceeded::class, AssetMonitoringChanged::class]);

        [$team] = $this->setupTeam(assetLimit: 2);
        $asset = Asset::factory()->pendingMonitoring()->create(['team_id' => $team->id]);

        $result = app(SetAssetMonitoring::class)->execute($asset, AssetMonitoringState::Monitored);

        $this->assertTrue($result['changed']);
        $this->assertFalse($result['over_cap']);
        $this->assertSame(1, $result['monitored']);
        $this->assertSame(2, $result['cap']);
        $this->assertSame(AssetMonitoringState::Monitored, $asset->fresh()->monitoring_state);
        $this->assertNotNull($asset->fresh()->monitoring_changed_at);

        Event::assertNotDispatched(UsageLimitExceeded::class);
        Event::assertDispatched(AssetMonitoringChanged::class, fn (AssetMonitoringChanged $e) => $e->assetId === $asset->id
            && $e->newState === 'monitored' && $e->overCap === false);
        $this->assertDatabaseHas('audit_logs', [
            'team_id' => $team->id,
            'action' => 'asset.monitoring_changed',
            'entity_id' => $asset->id,
        ]);
    }

    public function test_switching_on_beyond_the_cap_is_allowed_and_flagged_as_billable_extra(): void
    {
        Event::fake([UsageLimitExceeded::class]);

        [$team] = $this->setupTeam(assetLimit: 1);
        Asset::factory()->create(['team_id' => $team->id]); // already monitored → at the cap
        $extra = Asset::factory()->pendingMonitoring()->create(['team_id' => $team->id]);

        $result = app(SetAssetMonitoring::class)->execute($extra, AssetMonitoringState::Monitored);

        $this->assertTrue($result['changed'], 'Soft cap: the client decides, the excess is billed');
        $this->assertTrue($result['over_cap']);
        $this->assertSame(2, $result['monitored']);
        $this->assertSame(AssetMonitoringState::Monitored, $extra->fresh()->monitoring_state);

        Event::assertDispatched(UsageLimitExceeded::class, fn (UsageLimitExceeded $e) => $e->teamId === $team->id
            && $e->meterCode === 'monitored_assets' && $e->consumed === 2 && $e->included === 1);
    }

    public function test_excluding_a_unit_takes_it_out_of_monitoring(): void
    {
        [$team] = $this->setupTeam(assetLimit: null);
        $asset = Asset::factory()->create(['team_id' => $team->id]);

        $result = app(SetAssetMonitoring::class)->execute($asset, AssetMonitoringState::Excluded, reason: 'Unidad vendida');

        $this->assertTrue($result['changed']);
        $this->assertSame(AssetMonitoringState::Excluded, $asset->fresh()->monitoring_state);
        $this->assertSame(0, Asset::withoutGlobalScopes()->where('team_id', $team->id)->monitored()->count());
    }

    public function test_setting_the_same_state_is_a_no_op(): void
    {
        Event::fake([AssetMonitoringChanged::class]);

        [$team] = $this->setupTeam(assetLimit: null);
        $asset = Asset::factory()->create(['team_id' => $team->id]);

        $result = app(SetAssetMonitoring::class)->execute($asset, AssetMonitoringState::Monitored);

        $this->assertFalse($result['changed']);
        Event::assertNotDispatched(AssetMonitoringChanged::class);
    }

    public function test_switching_monitoring_never_touches_another_tenant(): void
    {
        $victim = Team::factory()->create();
        $actor = Team::factory()->create();

        Asset::factory()->count(3)->create(['team_id' => $victim->id]);
        TenantFeature::withoutGlobalScopes()->create([
            'team_id' => $actor->id,
            'feature_key' => 'monitored_assets',
            'enabled' => true,
            'source' => FeatureSource::ManualOverride,
            'limits_json' => ['included_quantity' => 1],
        ]);
        $own = Asset::factory()->pendingMonitoring()->create(['team_id' => $actor->id]);

        $result = $this->assertNoTenantLeak(
            $actor,
            fn () => app(SetAssetMonitoring::class)->execute($own, AssetMonitoringState::Monitored),
        );

        // The cap check counts only the actor's fleet: the victim's three
        // monitored units must not push the actor over its cap of one.
        $this->assertSame(1, $result['monitored']);
        $this->assertFalse($result['over_cap']);
    }
}
