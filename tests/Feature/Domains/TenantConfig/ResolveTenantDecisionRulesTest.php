<?php

namespace Tests\Feature\Domains\TenantConfig;

use App\Contracts\TenantConfig\TenantDecisionRulesResolver;
use App\Domains\Decisions\Data\TenantDecisionPolicy;
use App\Domains\TenantConfig\Actions\ResolveTenantDecisionRules;
use App\Domains\TenantConfig\Actions\UpdateTenantAIProfile;
use App\Domains\TenantConfig\Enums\AutomationLevel;
use App\Domains\TenantConfig\Enums\FalsePositiveTolerance;
use App\Domains\TenantConfig\Enums\MediaStrategy;
use App\Domains\TenantConfig\Enums\RiskTolerance;
use App\Domains\TenantConfig\Models\TenantAIProfile;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResolveTenantDecisionRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_resolves_to_tenantconfig_action(): void
    {
        $this->assertInstanceOf(
            ResolveTenantDecisionRules::class,
            app(TenantDecisionRulesResolver::class),
        );
    }

    public function test_tenant_without_profile_keeps_the_historical_default(): void
    {
        $team = Team::factory()->create();

        $policy = app(TenantDecisionRulesResolver::class)->resolve($team->id);

        $this->assertInstanceOf(TenantDecisionPolicy::class, $policy);
        $this->assertSame(0.5, $policy->humanReviewConfidenceThreshold);
        $this->assertSame('assisted', $policy->automationLevel);
        $this->assertSame('default', $policy->defaultRuleSetCode);
        $this->assertTrue($policy->allowAutomatedIncidents);
    }

    /**
     * @return array<string, array{AutomationLevel, float}>
     */
    public static function levels(): array
    {
        return [
            'conservative' => [AutomationLevel::Conservative, 1.01],
            'assisted' => [AutomationLevel::Assisted, 0.5],
            'semi_automatic' => [AutomationLevel::SemiAutomatic, 0.4],
            'highly_automated' => [AutomationLevel::HighlyAutomated, 0.3],
        ];
    }

    #[DataProvider('levels')]
    public function test_each_persisted_level_maps_to_its_configured_threshold(AutomationLevel $level, float $expected): void
    {
        $team = Team::factory()->create();
        TenantAIProfile::factory()->create(['team_id' => $team->id, 'automation_level' => $level]);

        $policy = app(TenantDecisionRulesResolver::class)->resolve($team->id);

        $this->assertSame($expected, $policy->humanReviewConfidenceThreshold);
        $this->assertSame($level->value, $policy->automationLevel);
    }

    public function test_every_level_has_a_configured_threshold(): void
    {
        foreach (AutomationLevel::cases() as $level) {
            $this->assertIsFloat(ResolveTenantDecisionRules::humanReviewThreshold($level));
        }

        $this->assertGreaterThan(1.0, ResolveTenantDecisionRules::humanReviewThreshold(AutomationLevel::Conservative));
    }

    public function test_thresholds_come_from_config(): void
    {
        config()->set('ai.automation_levels.human_review_threshold.highly_automated', 0.2);
        $team = Team::factory()->create();
        TenantAIProfile::factory()->create(['team_id' => $team->id, 'automation_level' => AutomationLevel::HighlyAutomated]);

        $this->assertSame(0.2, app(TenantDecisionRulesResolver::class)->resolve($team->id)->humanReviewConfidenceThreshold);
    }

    public function test_updating_the_ai_profile_busts_the_cached_policy(): void
    {
        $team = Team::factory()->create();
        $resolver = app(TenantDecisionRulesResolver::class);

        // Calienta la caché con el nivel por defecto.
        $this->assertSame(0.5, $resolver->resolve($team->id)->humanReviewConfidenceThreshold);

        $this->updateLevel($team->id, AutomationLevel::Conservative);

        $policy = $resolver->resolve($team->id);
        $this->assertSame(1.01, $policy->humanReviewConfidenceThreshold);
        $this->assertSame('conservative', $policy->automationLevel);
    }

    public function test_policy_is_cached_between_profile_updates(): void
    {
        $team = Team::factory()->create();
        $resolver = app(TenantDecisionRulesResolver::class);
        $resolver->resolve($team->id);

        // Una escritura que no pasa por UpdateTenantAIProfile no invalida:
        // demuestra que la caché existe (y que el bust de arriba es real).
        TenantAIProfile::factory()->create(['team_id' => $team->id, 'automation_level' => AutomationLevel::Conservative]);

        $this->assertSame(0.5, $resolver->resolve($team->id)->humanReviewConfidenceThreshold);
    }

    public function test_one_tenant_level_never_affects_another(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $resolver = app(TenantDecisionRulesResolver::class);

        $this->assertSame(0.5, $resolver->resolve($teamB->id)->humanReviewConfidenceThreshold);

        $this->updateLevel($teamA->id, AutomationLevel::Conservative);

        $this->assertSame(1.01, $resolver->resolve($teamA->id)->humanReviewConfidenceThreshold);
        $this->assertSame(0.5, $resolver->resolve($teamB->id)->humanReviewConfidenceThreshold);
        $this->assertSame('assisted', $resolver->resolve($teamB->id)->automationLevel);

        $this->updateLevel($teamB->id, AutomationLevel::HighlyAutomated);

        $this->assertSame(1.01, $resolver->resolve($teamA->id)->humanReviewConfidenceThreshold);
        $this->assertSame(0.3, $resolver->resolve($teamB->id)->humanReviewConfidenceThreshold);
    }

    private function updateLevel(int $teamId, AutomationLevel $level): void
    {
        app(UpdateTenantAIProfile::class)->execute(
            teamId: $teamId,
            profileCode: 'custom',
            name: 'Perfil',
            description: null,
            riskTolerance: RiskTolerance::Medium,
            falsePositiveTolerance: FalsePositiveTolerance::Medium,
            automationLevel: $level,
            mediaStrategy: MediaStrategy::Preferred,
        );
    }
}
