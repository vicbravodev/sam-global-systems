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
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class EvaluateDecisionRulesTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DecisionOutcomeSeeder::class);
        $this->seed(IncidentsSeeder::class);
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

        $context = $this->assertSystemLogged('decisions.rules.evaluated');
        $this->assertSame(['safety-high-risk'], $context['calc']['matched']);
        $this->assertSame('safety-high-risk', $context['calc']['stopped_at']);
        $this->assertSame('global', $context['calc']['ruleset_scope']);
        $this->assertSame(1, $context['calc']['evaluated_count']);
        $this->assertSame(1, $context['calc']['candidate_count']);
        $this->assertSame(1, $context['result']['matched_count']);
        $this->assertSame('real_event', $context['calc']['facts']['classification']);
        $this->assertArrayNotHasKey('team_id', $context['calc']['facts']);
        $this->assertNoSensitiveDataLogged();
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

        $context = $this->assertSystemLogged('decisions.decision.already_exists', fn (array $c) => $c['input']['stage'] === 'evaluate_rules');
        $this->assertSame('decision_exists', $context['reason']);
        $this->assertSame($first->id, $context['result']['decision_id']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_missing_ruleset_logs_degraded_and_still_decides(): void
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

        $decision = app(EvaluateDecisionRules::class)->execute($eval);

        $this->assertNotNull($decision->id);
        $this->assertNull($decision->ruleset_id);
        $context = $this->assertSystemLogged('decisions.ruleset.missing');
        $this->assertSame('no_active_ruleset', $context['reason']);
        $this->assertSame('default', $context['input']['default_ruleset_code']);
        $this->assertSame($eval->id, $context['input']['ai_evaluation_id']);
        $this->assertSame('ai_mapping', $context['result']['falls_back_to']);
        $this->assertSystemNotLogged('decisions.rules.evaluated');
        $this->assertNoSensitiveDataLogged();
    }

    /**
     * @return array{0: AIEventEvaluation, 1: RuleSet}
     */
    private function evaluationWithGlobalRuleSet(): array
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

        return [$eval, RuleSet::factory()->global()->create(['code' => 'default'])];
    }

    public function test_unknown_operator_rule_is_logged_invalid_and_does_not_match(): void
    {
        [$eval, $ruleset] = $this->evaluationWithGlobalRuleSet();
        $baseline = app(EvaluateDecisionRules::class)->execute(
            AIEventEvaluation::factory()->create([
                'normalized_event_id' => NormalizedEvent::factory()->create(['team_id' => $eval->team_id])->id,
                'team_id' => $eval->team_id,
                'classification' => EventClassification::RealEvent,
                'risk_score' => 0.7,
                'confidence_score' => 0.9,
                'priority_level' => EvaluationPriority::Normal,
            ]),
        );
        $this->setUpAssertsSystemLog();

        $rule = DecisionRule::factory()->create([
            'ruleset_id' => $ruleset->id,
            'code' => 'bad-operator',
            'conditions_json' => ['all' => [['field' => 'risk_score', 'operator' => 'between', 'value' => 1]]],
        ]);

        $decision = app(EvaluateDecisionRules::class)->execute($eval);

        $this->assertSame($baseline->decision_code, $decision->decision_code);
        $context = $this->assertSystemLogged('decisions.rule.invalid');
        $this->assertSame('unknown_operator', $context['reason']);
        $this->assertSame($rule->id, $context['input']['rule_id']);
        $this->assertSame('bad-operator', $context['input']['rule_code']);
        $this->assertSame($ruleset->id, $context['input']['ruleset_id']);
        $this->assertSame('$.all.0', $context['calc']['problems'][0]['path']);
        $this->assertSame('between', $context['calc']['problems'][0]['operator']);
        $this->assertSame('risk_score', $context['calc']['problems'][0]['field']);
        $this->assertSame(1, $context['calc']['problems_count']);
        $this->assertFalse($context['result']['invalid_nodes_evaluate_as']);

        $evaluated = $this->assertSystemLogged('decisions.rules.evaluated');
        $this->assertSame([], $evaluated['calc']['matched']);
        $this->assertSame(1, $evaluated['calc']['evaluated_count']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_malformed_condition_rule_is_logged_invalid_and_does_not_match(): void
    {
        [$eval, $ruleset] = $this->evaluationWithGlobalRuleSet();
        $baseline = app(EvaluateDecisionRules::class)->execute(
            AIEventEvaluation::factory()->create([
                'normalized_event_id' => NormalizedEvent::factory()->create(['team_id' => $eval->team_id])->id,
                'team_id' => $eval->team_id,
                'classification' => EventClassification::RealEvent,
                'risk_score' => 0.7,
                'confidence_score' => 0.9,
                'priority_level' => EvaluationPriority::Normal,
            ]),
        );
        $this->setUpAssertsSystemLog();

        DecisionRule::factory()->create([
            'ruleset_id' => $ruleset->id,
            'code' => 'bad-shape',
            'conditions_json' => ['foo' => 'bar'],
        ]);

        $decision = app(EvaluateDecisionRules::class)->execute($eval);

        $this->assertSame($baseline->decision_code, $decision->decision_code);
        $context = $this->assertSystemLogged('decisions.rule.invalid');
        $this->assertSame('malformed_condition', $context['reason']);
        $this->assertSame('$', $context['calc']['problems'][0]['path']);
        $this->assertNull($context['calc']['problems'][0]['operator']);

        $evaluated = $this->assertSystemLogged('decisions.rules.evaluated');
        $this->assertNotContains('bad-shape', $evaluated['calc']['matched']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_rule_free_text_name_is_never_logged(): void
    {
        [$eval, $ruleset] = $this->evaluationWithGlobalRuleSet();

        DecisionRule::factory()->create([
            'ruleset_id' => $ruleset->id,
            'name' => 'Nombre libre xyz',
            'conditions_json' => ['foo' => 'bar'],
        ]);
        DecisionRule::factory()->create([
            'ruleset_id' => $ruleset->id,
            'name' => 'Nombre libre xyz',
        ]);

        app(EvaluateDecisionRules::class)->execute($eval);

        $this->assertNotSame([], $this->systemLogEntries('decisions.rules.evaluated'));
        $this->assertStringNotContainsString('Nombre libre xyz', json_encode($this->systemLogEntries()));
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
