<?php

namespace Tests\Feature\Domains\TenantConfig;

use App\Domains\TenantConfig\Actions\ApplyTenantRuleOverrides;
use App\Domains\TenantConfig\Enums\RuleOverrideType;
use App\Domains\TenantConfig\Models\TenantRuleOverride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplyTenantRuleOverridesTest extends TestCase
{
    use RefreshDatabase;

    public function test_disable_rule_short_circuits(): void
    {
        $team = User::factory()->create()->currentTeam;

        TenantRuleOverride::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'base_rule_code' => 'speed_violation',
            'override_type' => RuleOverrideType::DisableRule,
            'override_config_json' => [],
            'is_active' => true,
        ]);

        $resolved = app(ApplyTenantRuleOverrides::class)
            ->apply($team->id, 'speed_violation', ['threshold_mph' => 75]);

        $this->assertTrue($resolved->disabled);
        $this->assertCount(1, $resolved->appliedOverrides);
    }

    public function test_change_threshold_merges_into_parameters(): void
    {
        $team = User::factory()->create()->currentTeam;

        TenantRuleOverride::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'base_rule_code' => 'speed_violation',
            'override_type' => RuleOverrideType::ChangeThreshold,
            'override_config_json' => ['threshold_mph' => 80],
            'is_active' => true,
        ]);

        $resolved = app(ApplyTenantRuleOverrides::class)
            ->apply($team->id, 'speed_violation', ['threshold_mph' => 75, 'window_seconds' => 30]);

        $this->assertSame(80, $resolved->parameters['threshold_mph']);
        $this->assertSame(30, $resolved->parameters['window_seconds']);
    }

    public function test_multiple_overrides_compose_on_same_rule(): void
    {
        $team = User::factory()->create()->currentTeam;

        TenantRuleOverride::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'base_rule_code' => 'harsh_braking',
            'override_type' => RuleOverrideType::ChangeThreshold,
            'override_config_json' => ['min_g_force' => 0.7],
            'is_active' => true,
        ]);

        TenantRuleOverride::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'base_rule_code' => 'harsh_braking',
            'override_type' => RuleOverrideType::ChangePriority,
            'override_config_json' => ['priority' => 'high'],
            'is_active' => true,
        ]);

        $resolved = app(ApplyTenantRuleOverrides::class)
            ->apply($team->id, 'harsh_braking', ['min_g_force' => 0.5]);

        $this->assertSame(0.7, $resolved->parameters['min_g_force']);
        $this->assertSame('high', $resolved->priority);
        $this->assertCount(2, $resolved->appliedOverrides);
    }

    public function test_inactive_overrides_are_ignored(): void
    {
        $team = User::factory()->create()->currentTeam;

        TenantRuleOverride::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'base_rule_code' => 'speed_violation',
            'override_type' => RuleOverrideType::DisableRule,
            'override_config_json' => [],
            'is_active' => false,
        ]);

        $resolved = app(ApplyTenantRuleOverrides::class)
            ->apply($team->id, 'speed_violation');

        $this->assertFalse($resolved->disabled);
        $this->assertCount(0, $resolved->appliedOverrides);
    }

    public function test_force_human_review_flag(): void
    {
        $team = User::factory()->create()->currentTeam;

        TenantRuleOverride::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'base_rule_code' => 'speed_violation',
            'override_type' => RuleOverrideType::ForceHumanReview,
            'override_config_json' => [],
            'is_active' => true,
        ]);

        $resolved = app(ApplyTenantRuleOverrides::class)->apply($team->id, 'speed_violation');

        $this->assertTrue($resolved->forceHumanReview);
        $this->assertFalse($resolved->disabled);
    }
}
