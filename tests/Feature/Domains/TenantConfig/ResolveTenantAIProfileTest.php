<?php

namespace Tests\Feature\Domains\TenantConfig;

use App\Contracts\TenantConfig\TenantAIProfileResolver;
use App\Domains\TenantConfig\Actions\ResolveTenantAIProfile;
use App\Domains\TenantConfig\Enums\AutomationLevel;
use App\Domains\TenantConfig\Enums\FalsePositiveTolerance;
use App\Domains\TenantConfig\Enums\MediaStrategy;
use App\Domains\TenantConfig\Enums\RiskTolerance;
use App\Domains\TenantConfig\Models\TenantAIProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveTenantAIProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_persisted_profile_when_present(): void
    {
        $team = User::factory()->create()->currentTeam;

        TenantAIProfile::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'profile_code' => 'aggressive',
            'name' => 'Aggressive Profile',
            'description' => null,
            'risk_tolerance' => RiskTolerance::High,
            'false_positive_tolerance' => FalsePositiveTolerance::Low,
            'automation_level' => AutomationLevel::HighlyAutomated,
            'media_strategy' => MediaStrategy::WaitBeforeDeciding,
            'is_active' => true,
        ]);

        $resolved = app(ResolveTenantAIProfile::class)->resolve($team->id);

        $this->assertTrue($resolved->isPersisted);
        $this->assertSame('aggressive', $resolved->profileCode);
        $this->assertSame(AutomationLevel::HighlyAutomated, $resolved->automationLevel);
        $this->assertSame(MediaStrategy::WaitBeforeDeciding, $resolved->mediaStrategy);
        $this->assertSame(RiskTolerance::High, $resolved->riskTolerance);
    }

    public function test_returns_default_profile_when_none_persisted(): void
    {
        $team = User::factory()->create()->currentTeam;

        $resolved = app(TenantAIProfileResolver::class)->resolve($team->id);

        $this->assertFalse($resolved->isPersisted);
        $this->assertSame('system_default', $resolved->profileCode);
        $this->assertSame(AutomationLevel::Assisted, $resolved->automationLevel);
        $this->assertSame(MediaStrategy::Preferred, $resolved->mediaStrategy);
    }

    public function test_inactive_profile_is_treated_as_absent(): void
    {
        $team = User::factory()->create()->currentTeam;

        TenantAIProfile::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'profile_code' => 'archived',
            'name' => 'Archived',
            'description' => null,
            'risk_tolerance' => RiskTolerance::Low,
            'false_positive_tolerance' => FalsePositiveTolerance::Low,
            'automation_level' => AutomationLevel::Conservative,
            'media_strategy' => MediaStrategy::Optional,
            'is_active' => false,
        ]);

        $resolved = app(ResolveTenantAIProfile::class)->resolve($team->id);

        $this->assertFalse($resolved->isPersisted);
        $this->assertSame(AutomationLevel::Assisted, $resolved->automationLevel);
    }
}
