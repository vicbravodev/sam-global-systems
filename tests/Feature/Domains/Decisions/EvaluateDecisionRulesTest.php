<?php

namespace Tests\Feature\Domains\Decisions;

use App\Domains\AI\Enums\EvaluationPriority;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Decisions\Actions\EvaluateDecisionRules;
use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Enums\DecisionSourceType;
use App\Domains\Decisions\Enums\RuleScope;
use App\Domains\Decisions\Events\DecisionMade;
use App\Domains\Decisions\Events\EscalationTriggered;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionOutcome;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Decisions\Models\DecisionTrace;
use App\Domains\Decisions\Models\EscalationPolicy;
use App\Domains\Decisions\Models\RuleSet;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\DecisionOutcomeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class EvaluateDecisionRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DecisionOutcomeSeeder::class);
    }

    public function test_panic_event_high_risk_creates_incident_decision_via_safety_rule(): void
    {
        Event::fake([DecisionMade::class]);

        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;
        $event = NormalizedEvent::factory()->create(['team_id' => $teamId]);
        $eval = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $teamId,
            'classification' => EventClassification::RealEvent,
            'risk_score' => 0.95,
            'confidence_score' => 0.92,
            'priority_level' => EvaluationPriority::Urgent,
        ]);

        $ruleset = RuleSet::factory()->global()->create(['code' => 'default']);
        $incidentOutcome = DecisionOutcome::firstWhere('code', DecisionOutcomeCode::Incident->value);
        DecisionRule::factory()->create([
            'team_id' => null,
            'ruleset_id' => $ruleset->id,
            'code' => 'safety-high-risk',
            'scope' => RuleScope::Global,
            'priority' => 100,
            'conditions_json' => [
                'all' => [
                    ['field' => 'classification', 'operator' => 'eq', 'value' => 'real_event'],
                    ['field' => 'risk_score', 'operator' => 'gte', 'value' => 0.9],
                ],
            ],
            'outcome_override' => $incidentOutcome->id,
            'stop_processing' => true,
        ]);

        $decision = app(EvaluateDecisionRules::class)->execute($eval);

        $this->assertSame(DecisionOutcomeCode::Incident->value, $decision->decision_code);
        Event::assertDispatched(DecisionMade::class);
    }

    public function test_low_confidence_forces_human_review(): void
    {
        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;
        $event = NormalizedEvent::factory()->create(['team_id' => $teamId]);
        $eval = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $teamId,
            'classification' => EventClassification::RealEvent,
            'risk_score' => 0.7,
            'confidence_score' => 0.3,
            'priority_level' => EvaluationPriority::Normal,
        ]);

        RuleSet::factory()->global()->create(['code' => 'default']);

        $decision = app(EvaluateDecisionRules::class)->execute($eval);

        $this->assertTrue($decision->requires_human_review);
        $this->assertSame(DecisionOutcomeCode::RequireHumanReview->value, $decision->decision_code);
    }

    public function test_tenant_rule_overrides_global_rule(): void
    {
        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;
        $event = NormalizedEvent::factory()->create(['team_id' => $teamId]);
        $eval = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $teamId,
            'classification' => EventClassification::RealEvent,
            'risk_score' => 0.5,
            'confidence_score' => 0.95,
            'priority_level' => EvaluationPriority::Normal,
        ]);

        $tenantSet = RuleSet::factory()->create([
            'team_id' => $teamId,
            'code' => 'tenant-default',
            'is_default' => true,
            'is_active' => true,
        ]);

        $logOutcome = DecisionOutcome::firstWhere('code', DecisionOutcomeCode::LogOnly->value);
        DecisionRule::factory()->create([
            'team_id' => $teamId,
            'ruleset_id' => $tenantSet->id,
            'code' => 'tenant-mute',
            'scope' => RuleScope::Tenant,
            'priority' => 50,
            'conditions_json' => [
                'all' => [
                    ['field' => 'classification', 'operator' => 'eq', 'value' => 'real_event'],
                ],
            ],
            'outcome_override' => $logOutcome->id,
            'stop_processing' => false,
        ]);

        $decision = app(EvaluateDecisionRules::class)->execute($eval);

        $this->assertSame(DecisionOutcomeCode::LogOnly->value, $decision->decision_code);
        $this->assertSame($tenantSet->id, $decision->ruleset_id);
    }

    public function test_decision_trace_records_steps(): void
    {
        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;
        $event = NormalizedEvent::factory()->create(['team_id' => $teamId]);
        $eval = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $teamId,
            'classification' => EventClassification::RealEvent,
            'risk_score' => 0.7,
            'confidence_score' => 0.95,
            'priority_level' => EvaluationPriority::High,
        ]);

        RuleSet::factory()->global()->create(['code' => 'default']);

        $decision = app(EvaluateDecisionRules::class)->execute($eval);

        $traces = DecisionTrace::where('decision_id', $decision->id)->orderBy('step_order')->get();

        $this->assertGreaterThanOrEqual(2, $traces->count());
        $this->assertSame(DecisionSourceType::Ai, $traces->first()->source_type);
    }

    public function test_fallback_outcome_when_no_rules_match(): void
    {
        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;
        $event = NormalizedEvent::factory()->create(['team_id' => $teamId]);
        $eval = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $teamId,
            'classification' => EventClassification::Noise,
            'risk_score' => 0.1,
            'confidence_score' => 0.95,
            'priority_level' => EvaluationPriority::Low,
        ]);

        RuleSet::factory()->global()->create(['code' => 'default']);

        $decision = app(EvaluateDecisionRules::class)->execute($eval);

        $this->assertSame(DecisionOutcomeCode::LogOnly->value, $decision->decision_code);
    }

    public function test_escalation_triggered_dispatches_event(): void
    {
        Event::fake([EscalationTriggered::class]);

        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;
        $event = NormalizedEvent::factory()->create(['team_id' => $teamId]);
        $eval = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $teamId,
            'classification' => EventClassification::RealEvent,
            'risk_score' => 0.95,
            'confidence_score' => 0.95,
            'priority_level' => EvaluationPriority::Urgent,
        ]);

        $policy = EscalationPolicy::factory()->create(['team_id' => $teamId]);
        $ruleset = RuleSet::factory()->global()->create(['code' => 'default']);
        $escalateOutcome = DecisionOutcome::firstWhere('code', DecisionOutcomeCode::Escalate->value);
        DecisionRule::factory()->create([
            'ruleset_id' => $ruleset->id,
            'team_id' => null,
            'code' => 'global-escalate',
            'priority' => 100,
            'conditions_json' => [
                'all' => [
                    ['field' => 'classification', 'operator' => 'eq', 'value' => 'real_event'],
                    ['field' => 'risk_score', 'operator' => 'gte', 'value' => 0.9],
                ],
            ],
            'outcome_override' => $escalateOutcome->id,
            'escalation_policy_id' => $policy->id,
            'stop_processing' => true,
        ]);

        $decision = app(EvaluateDecisionRules::class)->execute($eval);

        $this->assertSame(DecisionOutcomeCode::Escalate->value, $decision->decision_code);
        $this->assertSame($policy->id, $decision->escalation_policy_id);
        Event::assertDispatched(EscalationTriggered::class);
    }

    public function test_idempotent_when_called_twice_for_same_evaluation(): void
    {
        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;
        $event = NormalizedEvent::factory()->create(['team_id' => $teamId]);
        $eval = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $teamId,
            'classification' => EventClassification::RealEvent,
            'risk_score' => 0.7,
            'confidence_score' => 0.9,
            'priority_level' => EvaluationPriority::Normal,
        ]);

        RuleSet::factory()->global()->create(['code' => 'default']);

        $first = app(EvaluateDecisionRules::class)->execute($eval);
        $second = app(EvaluateDecisionRules::class)->execute($eval);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Decision::withoutGlobalScopes()->where('ai_evaluation_id', $eval->id)->count());
    }

    public function test_deterministic_same_input_produces_same_outcome(): void
    {
        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;

        $eventA = NormalizedEvent::factory()->create(['team_id' => $teamId]);
        $eventB = NormalizedEvent::factory()->create(['team_id' => $teamId]);

        $payload = [
            'team_id' => $teamId,
            'classification' => EventClassification::RealEvent,
            'risk_score' => 0.75,
            'confidence_score' => 0.9,
            'priority_level' => EvaluationPriority::High,
        ];

        $evalA = AIEventEvaluation::factory()->create(['normalized_event_id' => $eventA->id] + $payload);
        $evalB = AIEventEvaluation::factory()->create(['normalized_event_id' => $eventB->id] + $payload);

        RuleSet::factory()->global()->create(['code' => 'default']);

        $decisionA = app(EvaluateDecisionRules::class)->execute($evalA);
        $decisionB = app(EvaluateDecisionRules::class)->execute($evalB);

        $this->assertSame($decisionA->decision_code, $decisionB->decision_code);
        $this->assertSame($decisionA->priority_level, $decisionB->priority_level);
    }
}
