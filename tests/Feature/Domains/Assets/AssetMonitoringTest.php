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
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Vigilancia por activo (decisión 2026-09-28): el sync descubre toda la
 * flota como `pending`, el cliente enciende lo que quiere y el tope es
 * SUAVE — encender de más se permite, se avisa y se cobra como extra.
 */
class AssetMonitoringTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

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

        $changed = $this->assertSystemLogged('assets.monitoring.changed', fn (array $c) => $c['input']['asset_id'] === $extra->id);
        $calc = $changed['calc'];
        $this->assertSame($team->id, $changed['input']['team_id']);
        $this->assertSame('pending', $calc['previous_state']);
        $this->assertSame('monitored', $calc['new_state']);
        $this->assertSame(1, $calc['assets_monitored_before']);
        $this->assertSame(2, $calc['assets_monitored_after']);
        $this->assertSame(1, $calc['cap']);
        $this->assertTrue($calc['over_cap']);
        $this->assertSame($calc['assets_monitored_after'] - $calc['cap'], $calc['overage_assets']);
        $this->assertSame(1, $calc['overage_assets']);
        $this->assertTrue($calc['tenant_billable']);
        $this->assertFalse($calc['reason_present']);
        $this->assertTrue($changed['result']['limit_event_dispatched']);

        $limit = $this->assertSystemLogged('billing.asset_limit.resolved', fn (array $c) => $c['input']['stage'] === 'monitoring_toggle');
        $this->assertSame('tenant_feature', $limit['calc']['source']);
        $this->assertSame(1, $limit['calc']['feature_limit']);
        $this->assertSame(1, $limit['result']['cap']);
        $this->assertSame(['team_id' => $team->id, 'stage' => 'monitoring_toggle'], $limit['input']);
        $this->assertNoSensitiveDataLogged();
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

        $context = $this->assertSystemLogged('assets.monitoring.changed', fn (array $c) => ($c['reason'] ?? null) === 'same_state');
        $this->assertSame(['team_id' => $team->id, 'asset_id' => $asset->id], $context['input']);
        $this->assertSame(['state' => 'monitored'], $context['calc']);
        $this->assertSame(1, count($this->systemLogEntries('assets.monitoring.changed')));
    }

    public function test_a_switch_that_rolls_back_is_never_logged_as_changed(): void
    {
        [$team] = $this->setupTeam(assetLimit: null);
        $asset = Asset::factory()->pendingMonitoring()->create(['team_id' => $team->id]);

        try {
            DB::transaction(function () use ($asset) {
                app(SetAssetMonitoring::class)->execute($asset, AssetMonitoringState::Monitored, reason: 'Encendida a prueba');

                throw new RuntimeException('boom');
            });
            $this->fail('The transaction should have thrown');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(AssetMonitoringState::Pending, $asset->fresh()->monitoring_state);
        $this->assertSystemNotLogged('assets.monitoring.changed');
        $this->assertSystemNotLogged('billing.usage.recorded');
    }

    public function test_switching_on_a_suspended_tenant_logs_the_block_once(): void
    {
        [$team] = $this->setupTeam(assetLimit: null);
        Subscription::factory()->suspended()->create(['team_id' => $team->id]);
        $assets = Asset::factory()->pendingMonitoring()->count(2)->create(['team_id' => $team->id]);

        app(SetAssetMonitoring::class)->executeMany($team->id, $assets, AssetMonitoringState::Monitored);

        $blocked = $this->systemLogEntries('billing.tenant.blocked');
        $this->assertCount(1, $blocked);
        $this->assertSame('subscription_suspended', $blocked[0]['context']['reason']);
        $this->assertSame(['team_id' => $team->id, 'stage' => 'monitoring_toggle'], $blocked[0]['context']['input']);

        $changed = $this->systemLogEntries('assets.monitoring.changed');
        $this->assertCount(2, $changed);
        foreach ($changed as $entry) {
            $this->assertFalse($entry['context']['calc']['tenant_billable']);
            $this->assertSame('tenant_not_billable', $entry['context']['calc']['asset_day_outcome']);
        }
        $this->assertSystemLogged('billing.monitored_day.skipped', fn (array $c) => ($c['reason'] ?? null) === 'tenant_not_billable'
            && $c['calc']['blocked_reason'] === 'resolved_by_caller');
    }

    public function test_a_suspended_tenant_batch_that_switches_nothing_on_does_not_log_the_block(): void
    {
        [$team] = $this->setupTeam(assetLimit: null);
        Subscription::factory()->suspended()->create(['team_id' => $team->id]);
        $assets = Asset::factory()->count(2)->create(['team_id' => $team->id]);

        $result = app(SetAssetMonitoring::class)->executeMany($team->id, $assets, AssetMonitoringState::Monitored);

        $this->assertSame(0, $result['changed']);
        $this->assertSystemNotLogged('billing.tenant.blocked');
        $this->assertCount(2, $this->systemLogEntries('assets.monitoring.changed'));

        app(SetAssetMonitoring::class)->execute($assets->first(), AssetMonitoringState::Monitored);

        $this->assertSystemNotLogged('billing.tenant.blocked');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_block_is_logged_when_the_first_unit_is_actually_switched_on(): void
    {
        [$team] = $this->setupTeam(assetLimit: null);
        Subscription::factory()->suspended()->create(['team_id' => $team->id]);
        $already = Asset::factory()->create(['team_id' => $team->id]);
        $pending = Asset::factory()->pendingMonitoring()->count(2)->create(['team_id' => $team->id]);

        app(SetAssetMonitoring::class)->executeMany($team->id, [$already, ...$pending], AssetMonitoringState::Monitored);

        $codes = array_map(
            fn (array $e) => $e['code'].'/'.($e['context']['reason'] ?? 'ok'),
            array_values(array_filter($this->systemLogEntries(), fn (array $e) => in_array($e['code'], ['billing.tenant.blocked', 'assets.monitoring.changed'], true))),
        );
        // Once per call, and only once a unit is really switched on — never
        // ahead of the unit that was already monitored.
        $this->assertSame([
            'assets.monitoring.changed/same_state',
            'billing.tenant.blocked/subscription_suspended',
            'assets.monitoring.changed/ok',
            'assets.monitoring.changed/ok',
        ], $codes);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_batch_skips_another_tenants_unit_without_naming_it(): void
    {
        [$team] = $this->setupTeam(assetLimit: null);
        $other = Team::factory()->create();
        $mine = Asset::factory()->pendingMonitoring()->create(['team_id' => $team->id]);
        $foreign = Asset::factory()->pendingMonitoring()->create(['team_id' => $other->id]);

        $result = app(SetAssetMonitoring::class)->executeMany($team->id, [$mine, $foreign], AssetMonitoringState::Monitored);

        $this->assertSame(1, $result['changed']);
        $skip = $this->assertSystemLogged('assets.monitoring.changed', fn (array $c) => ($c['reason'] ?? null) === 'other_tenant');
        $this->assertSame(['team_id' => $team->id], $skip['input']);
        $this->assertSame(['team_matches' => false], $skip['calc']);
        $this->assertCount(1, $this->systemLogEntries('billing.asset_limit.resolved'));
        $this->assertNoSensitiveDataLogged();
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

        $json = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('"team_id":'.$victim->id.',', $json);
        $this->assertStringNotContainsString('"team_id":'.$victim->id.'}', $json);
        foreach (Asset::withoutGlobalScopes()->pluck('name') as $name) {
            $this->assertStringNotContainsString(json_encode($name), $json);
        }
        $this->assertSystemLogged('assets.monitoring.changed', fn (array $c) => $c['input']['team_id'] === $actor->id
            && $c['calc']['assets_monitored_before'] === 0 && $c['calc']['assets_monitored_after'] === 1);
        $this->assertNoSensitiveDataLogged();
    }
}
