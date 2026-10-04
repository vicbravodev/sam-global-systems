<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Drivers\Actions\ResolveHosMonitoringConfig;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class ResolveHosMonitoringConfigTest extends TestCase
{
    use AssertsTenantIsolation;
    use RefreshDatabase;

    private function enable(Team $team, bool $enabled = true): void
    {
        TenantFeature::factory()->create(['team_id' => $team->id, 'feature_key' => HosMonitoringConfig::FEATURE_KEY, 'enabled' => $enabled]);
    }

    public function test_without_the_feature_there_is_no_config(): void
    {
        $team = Team::factory()->create();

        $this->assertNull(app(ResolveHosMonitoringConfig::class)->execute($team->id));

        $this->enable($team, enabled: false);
        $this->assertNull(app(ResolveHosMonitoringConfig::class)->execute($team->id));
    }

    public function test_defaults_apply_when_the_tenant_saved_nothing(): void
    {
        $team = Team::factory()->create();
        $this->enable($team);

        $config = app(ResolveHosMonitoringConfig::class)->execute($team->id);

        $this->assertSame([], $config->tagIds);
        $this->assertSame(1800, $config->leadSeconds());
        $this->assertSame(18000, $config->cycleLeadSeconds());
        $this->assertSame(2100, $config->restCompleteExpireSeconds());
        $this->assertTrue($config->enabled(HosSituation::RestComplete));
    }

    public function test_the_tenant_setting_overrides_key_by_key(): void
    {
        $team = Team::factory()->create();
        $this->enable($team);
        TenantSetting::factory()->create([
            'team_id' => $team->id,
            'setting_key' => HosMonitoringConfig::SETTING_KEY,
            'setting_group' => SettingGroup::Compliance,
            'value_type' => SettingValueType::Json,
            'value_json' => [
                'tag_ids' => [4738197, '7076291'],
                'excluded_asset_ids' => ['12'],
                'situations' => ['rest_complete' => false],
                'lead_minutes' => [20, 10, 0],
            ],
        ]);

        $config = app(ResolveHosMonitoringConfig::class)->execute($team->id);

        $this->assertSame(['4738197', '7076291'], $config->tagIds);
        $this->assertSame([12], $config->excludedAssetIds);
        $this->assertFalse($config->enabled(HosSituation::RestComplete));
        $this->assertTrue($config->enabled(HosSituation::BreakDue));
        $this->assertTrue($config->enabled(HosSituation::Violation));
        $this->assertSame(1200, $config->leadSeconds());
    }

    public function test_no_cross_tenant_leak(): void
    {
        // Team A with feature enabled and a specific setting.
        $teamA = Team::factory()->create();
        $this->enable($teamA);
        TenantSetting::factory()->create([
            'team_id' => $teamA->id,
            'setting_key' => HosMonitoringConfig::SETTING_KEY,
            'setting_group' => SettingGroup::Compliance,
            'value_type' => SettingValueType::Json,
            'value_json' => [
                'tag_ids' => ['111'],
            ],
        ]);

        // Team B with nothing initially.
        $teamB = Team::factory()->create();

        // Team B without feature should get null.
        $this->assertNull(app(ResolveHosMonitoringConfig::class)->execute($teamB->id));

        // Enable feature for team B (no setting).
        $this->enable($teamB);

        // Team B should get defaults (empty tag_ids), never team A's tag_ids.
        $configB = $this->assertNoTenantLeak($teamB, fn () => app(ResolveHosMonitoringConfig::class)->execute($teamB->id));
        $this->assertSame([], $configB->tagIds);
        $this->assertNotContains('111', $configB->tagIds);
    }

    public function test_explicit_null_in_setting_falls_back_to_default(): void
    {
        $team = Team::factory()->create();
        $this->enable($team);
        TenantSetting::factory()->create([
            'team_id' => $team->id,
            'setting_key' => HosMonitoringConfig::SETTING_KEY,
            'setting_group' => SettingGroup::Compliance,
            'value_type' => SettingValueType::Json,
            'value_json' => [
                'rest_complete_expire_minutes' => null,
            ],
        ]);

        $config = app(ResolveHosMonitoringConfig::class)->execute($team->id);

        // Default is 35 minutes = 2100 seconds.
        $this->assertSame(2100, $config->restCompleteExpireSeconds());
    }
}
