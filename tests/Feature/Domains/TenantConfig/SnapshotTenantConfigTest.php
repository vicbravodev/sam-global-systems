<?php

namespace Tests\Feature\Domains\TenantConfig;

use App\Domains\TenantConfig\Actions\SnapshotTenantConfig;
use App\Domains\TenantConfig\Actions\UpdateTenantAIProfile;
use App\Domains\TenantConfig\Enums\AutomationLevel;
use App\Domains\TenantConfig\Enums\FalsePositiveTolerance;
use App\Domains\TenantConfig\Enums\MediaStrategy;
use App\Domains\TenantConfig\Enums\RiskTolerance;
use App\Domains\TenantConfig\Events\TenantAIProfileChanged;
use App\Domains\TenantConfig\Models\TenantConfigVersion;
use App\Domains\TenantConfig\Models\TenantRuleOverride;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SnapshotTenantConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_collects_all_current_state(): void
    {
        $team = User::factory()->create()->currentTeam;

        TenantSetting::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'setting_key' => 'ops.threshold',
            'setting_group' => 'operational',
            'value_json' => ['value' => 50],
            'value_type' => 'number',
            'version' => 1,
            'is_active' => true,
            'updated_by_type' => 'system',
            'updated_by_id' => null,
        ]);

        TenantRuleOverride::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'base_rule_code' => 'speed_violation',
            'override_type' => 'change_threshold',
            'override_config_json' => ['threshold_mph' => 80],
            'is_active' => true,
        ]);

        $version = app(SnapshotTenantConfig::class)->execute($team->id);

        $this->assertSame(1, $version->version);
        $this->assertCount(1, $version->snapshot_json['rule_overrides']);
        $this->assertSame(50, $version->snapshot_json['settings']['ops.threshold']['value']);
    }

    public function test_consecutive_snapshots_increment_version(): void
    {
        $team = User::factory()->create()->currentTeam;

        $first = app(SnapshotTenantConfig::class)->execute($team->id);
        $second = app(SnapshotTenantConfig::class)->execute($team->id);

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
    }

    public function test_updating_ai_profile_creates_snapshot_and_dispatches_event(): void
    {
        Event::fake();

        $team = User::factory()->create()->currentTeam;

        app(UpdateTenantAIProfile::class)->execute(
            teamId: $team->id,
            profileCode: 'aggressive',
            name: 'Aggressive',
            description: null,
            riskTolerance: RiskTolerance::High,
            falsePositiveTolerance: FalsePositiveTolerance::Low,
            automationLevel: AutomationLevel::HighlyAutomated,
            mediaStrategy: MediaStrategy::WaitBeforeDeciding,
        );

        Event::assertDispatched(TenantAIProfileChanged::class);

        $this->assertSame(
            1,
            TenantConfigVersion::withoutGlobalScopes()->where('team_id', $team->id)->count(),
        );
    }
}
