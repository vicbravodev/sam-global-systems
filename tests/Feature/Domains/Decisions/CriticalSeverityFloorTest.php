<?php

namespace Tests\Feature\Domains\Decisions;

use App\Domains\AI\Enums\EvaluationPriority;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Decisions\Actions\EvaluateDecisionRules;
use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Enums\DecisionPriority;
use App\Domains\Decisions\Enums\RuleScope;
use App\Domains\Decisions\Events\DecisionMade;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionOutcome;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Decisions\Models\RuleSet;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\DecisionOutcomeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Piso de seguridad: un evento de severidad crítica (pánico, colisión,
 * vuelco, manipulación) nunca termina por debajo de INCIDENT, diga lo que
 * diga la IA, la confianza, el fallback del agente o una regla del tenant.
 */
class CriticalSeverityFloorTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    private EventType $panicType;

    private EventSeverity $critical;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DecisionOutcomeSeeder::class);
        Event::fake([DecisionMade::class]);

        $this->teamId = User::factory()->create()->currentTeam->id;
        $this->critical = EventSeverity::factory()->critical()->create();

        $this->panicType = EventType::query()->create([
            'code' => 'panic_button',
            'name' => 'Botón de pánico',
            'category_id' => EventCategory::factory()->create()->id,
            'default_severity_id' => $this->critical->id,
            'is_active' => true,
        ]);
    }

    public function test_critical_panic_classified_false_positive_with_high_confidence_becomes_incident(): void
    {
        $decision = $this->decide($this->panicEvaluation(EventClassification::FalsePositive, confidence: 0.97));

        $this->assertSame(DecisionOutcomeCode::Incident->value, $decision->decision_code);
        $this->assertStringContainsString('Piso de seguridad', (string) $decision->decision_reason);
        $this->assertContains(
            $decision->priority_level,
            [DecisionPriority::High, DecisionPriority::Urgent, DecisionPriority::Critical],
        );
    }

    public function test_critical_panic_from_agent_fallback_unclear_becomes_incident(): void
    {
        $decision = $this->decide($this->panicEvaluation(EventClassification::Unclear, confidence: 0.4));

        $this->assertSame(DecisionOutcomeCode::Incident->value, $decision->decision_code);
        $this->assertTrue($decision->requires_human_review);
    }

    public function test_critical_panic_with_tenant_ignore_rule_and_stop_processing_stays_incident(): void
    {
        $ruleSet = RuleSet::factory()->create([
            'team_id' => $this->teamId,
            'code' => 'tenant-default',
            'is_default' => true,
            'is_active' => true,
        ]);

        DecisionRule::factory()->create([
            'team_id' => $this->teamId,
            'ruleset_id' => $ruleSet->id,
            'code' => 'silenciar-panicos',
            'scope' => RuleScope::EventType,
            'priority' => 200,
            'conditions_json' => [
                'all' => [
                    ['field' => 'event_type_code', 'operator' => 'eq', 'value' => 'panic_button'],
                ],
            ],
            'outcome_override' => DecisionOutcome::firstWhere('code', DecisionOutcomeCode::Ignore->value)->id,
            'stop_processing' => true,
            'is_active' => true,
        ]);

        $decision = $this->decide($this->panicEvaluation(EventClassification::RealEvent, confidence: 0.95));

        $this->assertSame(DecisionOutcomeCode::Incident->value, $decision->decision_code);
        $this->assertStringContainsString('Piso de seguridad', (string) $decision->decision_reason);
        $this->assertStringContainsString('silenciar-panicos', (string) $decision->decision_reason);
    }

    public function test_explicit_tenant_rule_routing_critical_panic_to_human_review_is_respected(): void
    {
        // Regla opt-in de falsa alarma (roadmap B6-P7): ya garantiza revisión
        // humana, el piso no la pisa.
        $ruleSet = RuleSet::factory()->create([
            'team_id' => $this->teamId,
            'code' => 'tenant-default',
            'is_default' => true,
            'is_active' => true,
        ]);

        DecisionRule::factory()->create([
            'team_id' => $this->teamId,
            'ruleset_id' => $ruleSet->id,
            'code' => 'panic-false-alarm-review',
            'scope' => RuleScope::EventType,
            'priority' => 200,
            'conditions_json' => [
                'all' => [
                    ['field' => 'event_type_code', 'operator' => 'eq', 'value' => 'panic_button'],
                ],
            ],
            'outcome_override' => DecisionOutcome::firstWhere('code', DecisionOutcomeCode::RequireHumanReview->value)->id,
            'stop_processing' => true,
            'is_active' => true,
        ]);

        $decision = $this->decide($this->panicEvaluation(EventClassification::RealEvent, confidence: 0.95));

        $this->assertSame(DecisionOutcomeCode::RequireHumanReview->value, $decision->decision_code);
        $this->assertTrue($decision->requires_human_review);
    }

    public function test_critical_escalation_is_not_lowered(): void
    {
        $decision = $this->decide($this->panicEvaluation(EventClassification::RealEvent, confidence: 0.95, risk: 0.95));

        $this->assertSame(DecisionOutcomeCode::Escalate->value, $decision->decision_code);
    }

    public function test_medium_severity_false_positive_is_still_ignored(): void
    {
        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'event_severity_id' => EventSeverity::factory()->medium()->create()->id,
        ]);

        $decision = $this->decide(AIEventEvaluation::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
            'classification' => EventClassification::FalsePositive,
            'confidence_score' => 0.97,
            'risk_score' => 0.1,
            'priority_level' => EvaluationPriority::Low,
        ]));

        $this->assertSame(DecisionOutcomeCode::Ignore->value, $decision->decision_code);
    }

    private function panicEvaluation(EventClassification $classification, float $confidence, float $risk = 0.2): AIEventEvaluation
    {
        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'event_type_id' => $this->panicType->id,
            'event_severity_id' => $this->critical->id,
        ]);

        return AIEventEvaluation::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
            'classification' => $classification,
            'confidence_score' => $confidence,
            'risk_score' => $risk,
            'priority_level' => EvaluationPriority::Low,
        ]);
    }

    private function decide(AIEventEvaluation $eval): Decision
    {
        return app(EvaluateDecisionRules::class)->execute($eval);
    }
}
