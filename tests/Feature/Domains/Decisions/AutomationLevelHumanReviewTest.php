<?php

namespace Tests\Feature\Domains\Decisions;

use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Decisions\Actions\EvaluateDecisionRules;
use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Events\DecisionMade;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\TenantConfig\Enums\AutomationLevel;
use App\Domains\TenantConfig\Models\TenantAIProfile;
use App\Models\Team;
use Database\Seeders\DecisionOutcomeSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * El «Nivel de autonomía» del tenant fija el umbral de confianza bajo el cual
 * el motor pide revisión humana (`confidence < threshold`).
 */
class AutomationLevelHumanReviewTest extends TestCase
{
    use AssertsSystemLog;
    use AssertsTenantIsolation;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DecisionOutcomeSeeder::class);
        $this->seed(IncidentsSeeder::class);
        Event::fake([DecisionMade::class]);
    }

    /**
     * @return array<string, array{?AutomationLevel, float, float, bool}>
     */
    public static function boundaries(): array
    {
        return [
            'conservative: confianza total igual va a revisión' => [AutomationLevel::Conservative, 1.0, 1.01, true],
            'assisted: justo debajo de 0.5' => [AutomationLevel::Assisted, 0.49, 0.5, true],
            'assisted: en 0.5' => [AutomationLevel::Assisted, 0.5, 0.5, false],
            'sin perfil: igual que assisted' => [null, 0.49, 0.5, true],
            'sin perfil: en 0.5' => [null, 0.5, 0.5, false],
            'semi_automatic: justo debajo de 0.4' => [AutomationLevel::SemiAutomatic, 0.39, 0.4, true],
            'semi_automatic: en 0.4' => [AutomationLevel::SemiAutomatic, 0.4, 0.4, false],
            'highly_automated: justo debajo de 0.3' => [AutomationLevel::HighlyAutomated, 0.29, 0.3, true],
            'highly_automated: en 0.3' => [AutomationLevel::HighlyAutomated, 0.3, 0.3, false],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_level_sets_human_review_at_the_boundary(?AutomationLevel $level, float $confidence, float $threshold, bool $expectedReview): void
    {
        $team = Team::factory()->create();

        if ($level !== null) {
            TenantAIProfile::factory()->create(['team_id' => $team->id, 'automation_level' => $level]);
        }

        $decision = app(EvaluateDecisionRules::class)->execute($this->realEvent($team->id, $confidence));

        $this->assertSame($expectedReview, $decision->requires_human_review);
        $this->assertSame(
            $expectedReview ? DecisionOutcomeCode::RequireHumanReview->value : DecisionOutcomeCode::Alert->value,
            $decision->decision_code,
        );

        $resolved = $this->assertSystemLogged('decisions.outcome.resolved');
        $this->assertSame(($level ?? AutomationLevel::Assisted)->value, $resolved['calc']['automation_level']);
        $this->assertSame($threshold, $resolved['calc']['human_review_threshold']);
        $this->assertSame($confidence, $resolved['calc']['confidence']);
        $this->assertSame($expectedReview, $resolved['calc']['review_by_confidence']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_one_tenant_level_never_changes_another_tenant_decision(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        TenantAIProfile::factory()->create(['team_id' => $teamA->id, 'automation_level' => AutomationLevel::Conservative]);

        $evalA = $this->realEvent($teamA->id, 0.9);
        $evalB = $this->realEvent($teamB->id, 0.9);

        $decisionA = $this->assertNoTenantLeak($teamA, fn () => app(EvaluateDecisionRules::class)->execute($evalA));
        $decisionB = $this->assertNoTenantLeak($teamB, fn () => app(EvaluateDecisionRules::class)->execute($evalB));

        $this->assertTrue($decisionA->requires_human_review);
        $this->assertFalse($decisionB->requires_human_review);
        $this->assertSame(DecisionOutcomeCode::Alert->value, $decisionB->decision_code);
        $this->assertSame(1, Decision::query()->withoutGlobalScopes()->where('team_id', $teamB->id)->count());
    }

    private function realEvent(int $teamId, float $confidence): AIEventEvaluation
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $teamId]);

        // Riesgo 0.5: evento real no crítico que la IA mapearía a ALERT.
        return AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $teamId,
            'classification' => EventClassification::RealEvent,
            'risk_score' => 0.5,
            'confidence_score' => $confidence,
        ]);
    }
}
