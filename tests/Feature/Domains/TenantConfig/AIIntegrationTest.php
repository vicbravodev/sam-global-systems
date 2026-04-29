<?php

namespace Tests\Feature\Domains\TenantConfig;

use App\Domains\AI\Actions\ResolveTenantAIProfile as AIResolveTenantAIProfile;
use App\Domains\TenantConfig\Enums\AutomationLevel;
use App\Domains\TenantConfig\Enums\FalsePositiveTolerance;
use App\Domains\TenantConfig\Enums\MediaStrategy;
use App\Domains\TenantConfig\Enums\RiskTolerance;
use App\Domains\TenantConfig\Models\TenantAIProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the AI domain's ResolveTenantAIProfile bridges into spec 16's
 * persisted `tenant_ai_profiles` table when present, while preserving the
 * existing default-fallback behavior.
 */
class AIIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_resolver_falls_back_to_legacy_default_without_persisted_profile(): void
    {
        $team = User::factory()->create()->currentTeam;

        $profile = app(AIResolveTenantAIProfile::class)->execute($team->id);

        $this->assertSame('semi', $profile->automationLevel);
        $this->assertGreaterThan(0, $profile->monthlyTokenLimit);
    }

    public function test_ai_resolver_picks_persisted_automation_level(): void
    {
        $team = User::factory()->create()->currentTeam;

        TenantAIProfile::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'profile_code' => 'aggressive',
            'name' => 'Aggressive',
            'risk_tolerance' => RiskTolerance::High,
            'false_positive_tolerance' => FalsePositiveTolerance::Low,
            'automation_level' => AutomationLevel::HighlyAutomated,
            'media_strategy' => MediaStrategy::WaitBeforeDeciding,
            'is_active' => true,
        ]);

        $profile = app(AIResolveTenantAIProfile::class)->execute($team->id);

        $this->assertSame('highly_automated', $profile->automationLevel);
        $this->assertGreaterThan(0, $profile->monthlyTokenLimit);
    }
}
