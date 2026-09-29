<?php

namespace Tests\Feature\Domains\AI;

use App\Contracts\AI\EventEvaluationAgent;
use App\Contracts\NullImplementations\NullEventEvaluationAgent;
use App\Domains\AI\Actions\DetectFalsePositive;
use App\Domains\AI\Actions\EvaluateEventWithAI;
use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Models\AIDecisionSignal;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIExplanation;
use App\Domains\AI\Models\AIInferenceLog;
use App\Domains\AI\Models\AIRecommendedAction;
use App\Domains\Context\Actions\BuildEventContext;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class EvaluateEventWithAITest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AIMeterSeeder::class);
    }

    public function test_event_evaluates_with_ai_agent_persisting_full_record(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'payload_normalized_json' => ['severity' => 'high'],
        ]);

        app(BuildEventContext::class)->execute($event);

        $evaluation = app(EvaluateEventWithAI::class)->execute($event->fresh());

        $this->assertSame($event->id, $evaluation->normalized_event_id);
        $this->assertSame(EvaluationMode::AiText, $evaluation->evaluation_mode);
        $this->assertSame(EventClassification::RealEvent, $evaluation->classification);
        $this->assertSame('null-agent:1.0', $evaluation->model_used);

        $this->assertDatabaseHas('ai_explanations', ['evaluation_id' => $evaluation->id]);
        $this->assertTrue(AIDecisionSignal::where('evaluation_id', $evaluation->id)->exists());
        $this->assertTrue(AIInferenceLog::where('evaluation_id', $evaluation->id)->exists());
        $this->assertTrue(AIRecommendedAction::where('evaluation_id', $evaluation->id)->exists());

        $ctx = $this->assertSystemLogged('ai.heuristics.evaluated');
        $this->assertNull($ctx['calc']['signature_source']);
        $this->assertNull($ctx['calc']['noise_match']);
        $this->assertFalse($ctx['calc']['duplicates_signal_present']);
        $this->assertNull($ctx['calc']['recent_duplicates_count']);
        $this->assertSame(3, $ctx['calc']['duplicate_threshold']);
        $this->assertSame(4, $ctx['calc']['known_noise_signatures_count']);
        $this->assertSame(['payload.signature', 'signals.recent_duplicates_count'], $ctx['calc']['signals_missing']);
        $this->assertFalse($ctx['result']['short_circuit']);
        $this->assertNull($ctx['result']['rule']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_free_form_signature_value_is_never_logged(): void
    {
        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create([
            'team_id' => $user->currentTeam->id,
            'payload_normalized_json' => ['severity' => 'low', 'signature' => 'algo-libre-xyz'],
        ]);

        app(EvaluateEventWithAI::class)->execute($event);

        $ctx = $this->assertSystemLogged('ai.heuristics.evaluated');
        $this->assertSame('signature', $ctx['calc']['signature_source']);
        $this->assertNull($ctx['calc']['noise_match']);
        $this->assertSame(['signals.recent_duplicates_count'], $ctx['calc']['signals_missing']);
        $this->assertStringNotContainsString('algo-libre-xyz', json_encode($this->systemLogEntries()));
    }

    public function test_false_positive_short_circuit_via_known_noise_signature(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'payload_normalized_json' => ['severity' => 'low', 'signature' => 'heartbeat'],
        ]);

        $evaluation = app(EvaluateEventWithAI::class)->execute($event);

        $this->assertSame(EvaluationMode::RulesOnly, $evaluation->evaluation_mode);
        $this->assertSame(EventClassification::FalsePositive, $evaluation->classification);
        $this->assertSame('rules_engine:1.0', $evaluation->model_used);
        $this->assertSame(
            'Resuelto sin IA por una regla automática: señal de ruido conocida (heartbeat).',
            $evaluation->explanation_text,
        );
        $this->assertSame(
            ['Regla automática: señal de ruido conocida (heartbeat).'],
            $evaluation->signals_json['reasoning_steps'],
        );
        $this->assertSame('known_noise_signature:heartbeat', $evaluation->signals_json['key_factors']['rule_reason']);

        $ctx = $this->assertSystemLogged('ai.heuristics.evaluated');
        $this->assertSame($event->id, $ctx['input']['normalized_event_id']);
        $this->assertSame('signature', $ctx['calc']['signature_source']);
        $this->assertSame('heartbeat', $ctx['calc']['noise_match']);
        $this->assertTrue($ctx['result']['short_circuit']);
        $this->assertSame('known_noise_signature', $ctx['result']['rule']);

        $entry = $this->systemLogEntries('ai.evaluation.rules_only')[0] ?? null;
        $this->assertNotNull($entry);
        $this->assertSame('info', $entry['level']);
        $this->assertSame('skipped', $entry['context']['outcome']);
        $this->assertSame('heuristic_short_circuit', $entry['context']['reason']);
        $this->assertSame($event->id, $entry['context']['input']['normalized_event_id']);
        $this->assertSame($evaluation->id, $entry['context']['result']['evaluation_id']);
        $this->assertSame('known_noise_signature', $entry['context']['result']['heuristic_rule']);
        $this->assertNull($entry['context']['result']['error_class']);

        $completed = $this->assertSystemLogged('ai.evaluation.completed');
        $this->assertSame('heuristic', $completed['input']['route']);
        $this->assertSame(0.95, $completed['calc']['base_confidence']);
        $this->assertNull($completed['calc']['agent_risk_delta']);
        $this->assertSame('rules_only', $completed['result']['mode']);
        $this->assertSame('false_positive', $completed['result']['classification']);
        $this->assertNull($completed['result']['input_tokens']);
        $this->assertSystemNotLogged('ai.evaluation.agent_failed');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_fallback_to_rules_only_when_agent_throws(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $agent = app(EventEvaluationAgent::class);
        $this->assertInstanceOf(NullEventEvaluationAgent::class, $agent);
        $agent->shouldFail = true;

        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'payload_normalized_json' => ['severity' => 'medium'],
        ]);

        $evaluation = app(EvaluateEventWithAI::class)->execute($event);

        $this->assertSame(EvaluationMode::RulesOnly, $evaluation->evaluation_mode);
        $this->assertSame(EventClassification::Unclear, $evaluation->classification);
        $this->assertSame('rules_engine:1.0', $evaluation->model_used);

        $this->assertDatabaseHas('ai_inference_logs', [
            'evaluation_id' => $evaluation->id,
            'status' => 'error',
        ]);

        // El mensaje crudo de la excepción nunca llega al operador.
        $this->assertSame(
            'El análisis de IA no estuvo disponible; se evalúa solo con reglas.',
            $evaluation->explanation_text,
        );
        $this->assertStringNotContainsString('simulated failure', (string) $evaluation->explanation_text);
        $this->assertStringNotContainsString('simulated failure', json_encode($evaluation->signals_json));
        $this->assertSame('RuntimeException', $evaluation->signals_json['key_factors']['error_class'] ?? null);
        $this->assertArrayNotHasKey('error', $evaluation->signals_json['key_factors']);

        $codes = array_column($this->systemLogEntries(), 'code');
        $failedAt = array_search('ai.evaluation.agent_failed', $codes, true);
        $rulesOnlyAt = array_search('ai.evaluation.rules_only', $codes, true);
        $this->assertIsInt($failedAt);
        $this->assertIsInt($rulesOnlyAt);
        $this->assertLessThan($rulesOnlyAt, $failedAt);

        $failed = $this->assertSystemLogged('ai.evaluation.agent_failed');
        $this->assertSame('agent_error', $failed['reason']);
        $this->assertSame($event->id, $failed['input']['normalized_event_id']);
        $this->assertSame(RuntimeException::class, $failed['error']['class']);
        $this->assertArrayNotHasKey('result', $failed);

        $entry = $this->systemLogEntries('ai.evaluation.rules_only')[0];
        $this->assertSame('warning', $entry['level']);
        $this->assertSame('degraded', $entry['context']['outcome']);
        $this->assertSame('agent_error', $entry['context']['reason']);
        $this->assertSame($evaluation->id, $entry['context']['result']['evaluation_id']);
        $this->assertSame('RuntimeException', $entry['context']['result']['error_class']);
        $this->assertNull($entry['context']['result']['heuristic_rule']);

        $completed = $this->assertSystemLogged('ai.evaluation.completed');
        $this->assertSame('agent_error', $completed['input']['route']);
        $this->assertSame(0.4, $completed['calc']['base_confidence']);
        $this->assertSame($completed['calc']['base_risk'], $completed['calc']['risk_after_agent']);
        $this->assertSame($evaluation->confidence_score, $completed['calc']['confidence']);
        $this->assertSame('rules_only', $completed['result']['mode']);
        $this->assertSame('rules_engine:1.0', $completed['result']['model']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_ai_route_narrates_the_risk_chain_priority_and_false_positive_check(): void
    {
        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create([
            'team_id' => $user->currentTeam->id,
            'payload_normalized_json' => ['severity' => 'high'],
        ]);

        app(BuildEventContext::class)->execute($event);

        $evaluation = app(EvaluateEventWithAI::class)->execute($event->fresh());

        // BuildEventContext ya evaluó una vez (cola sync); se afirma la línea de esta evaluación.
        $completed = $this->assertSystemLogged('ai.evaluation.completed', fn (array $c): bool => $c['result']['evaluation_id'] === $evaluation->id);
        $this->assertSame($event->id, $completed['input']['normalized_event_id']);
        $this->assertSame($evaluation->evaluation_version, $completed['input']['evaluation_version']);
        $this->assertSame('ai', $completed['input']['route']);
        $this->assertFalse($completed['input']['operator_feedback_present']);
        $this->assertSame($evaluation->id, $completed['result']['evaluation_id']);
        $this->assertSame('ai_text', $completed['result']['mode']);
        $this->assertSame('real_event', $completed['result']['classification']);
        $this->assertSame($evaluation->priority_level->value, $completed['result']['priority_level']);
        $this->assertSame('null-agent:1.0', $completed['result']['model']);
        $this->assertSame(120, $completed['result']['input_tokens']);
        $this->assertSame(60, $completed['result']['output_tokens']);
        $this->assertSame(5, $completed['result']['latency_ms']);
        $this->assertSame(0.0, $completed['result']['cost_estimate']);
        $this->assertSame(
            AIInferenceLog::where('evaluation_id', $evaluation->id)->value('id'),
            $completed['result']['ai_inference_log_id'],
        );

        // La cadena se rehace: base → delta del agente → delta de fusión → persistido.
        $calc = $completed['calc'];
        $this->assertSame(0.0, $calc['agent_risk_delta']);
        $this->assertSame(round(max(0.0, min(1.0, $calc['base_risk'] + $calc['agent_risk_delta'])), 2), $calc['risk_after_agent']);
        $this->assertSame(0.0, $calc['fusion_risk_delta']);
        $this->assertSame(0.0, $calc['fusion_confidence_delta']);
        $this->assertSame($calc['risk_after_agent'], $calc['risk_score']);
        $this->assertSame($evaluation->risk_score, $calc['risk_score']);
        $this->assertSame(0.85, $calc['base_confidence']);
        $this->assertSame($evaluation->confidence_score, $calc['confidence']);

        $riskEntries = $this->systemLogEntries('ai.risk.calculated');
        $risk = end($riskEntries)['context'];
        $this->assertSame($risk['result']['risk_score'], $calc['base_risk']);

        $priority = $this->assertSystemLogged('ai.priority.resolved', fn (array $c): bool => $c['input']['evaluation_id'] === $evaluation->id);
        $this->assertSame($evaluation->id, $priority['input']['evaluation_id']);
        $this->assertSame(['urgent' => 0.85, 'high' => 0.6, 'normal' => 0.3], $priority['calc']['thresholds']);
        $this->assertTrue($priority['calc']['actionable']);
        $this->assertSame('real_event', $priority['calc']['classification']);
        $this->assertSame($evaluation->risk_score, $priority['calc']['risk_score']);
        $thresholds = $priority['calc']['thresholds'];
        $riskScore = $priority['calc']['risk_score'];
        $expected = ! $priority['calc']['actionable'] ? 'low' : match (true) {
            $riskScore >= $thresholds['urgent'] => 'urgent',
            $riskScore >= $thresholds['high'] => 'high',
            $riskScore >= $thresholds['normal'] => 'normal',
            default => 'low',
        };
        $this->assertSame($expected, $priority['result']['priority_level']);
        $this->assertSame($evaluation->priority_level->value, $priority['result']['priority_level']);
        $this->assertSame($evaluation->requires_action, $priority['result']['requires_action']);

        $falsePositive = $this->assertSystemLogged('ai.false_positive.checked', fn (array $c): bool => $c['input']['evaluation_id'] === $evaluation->id);
        $this->assertSame($evaluation->id, $falsePositive['input']['evaluation_id']);
        $this->assertSame(0.85, $falsePositive['calc']['threshold']);
        $this->assertSame('real_event', $falsePositive['calc']['classification']);
        $this->assertSame($evaluation->confidence_score, $falsePositive['calc']['confidence']);
        $this->assertFalse($falsePositive['result']['is_false_positive']);

        $this->assertSystemNotLogged('ai.evaluation.rules_only');
        $this->assertSystemNotLogged('ai.evaluation.agent_failed');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_completed_line_logs_no_clamp_when_there_is_no_fusion(): void
    {
        $agent = app(EventEvaluationAgent::class);
        $agent->forcedConfidence = 1.0;

        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create([
            'team_id' => $user->currentTeam->id,
            'payload_normalized_json' => ['severity' => 'high'],
        ]);

        $evaluation = app(EvaluateEventWithAI::class)->execute($event);

        $calc = $this->assertSystemLogged('ai.evaluation.completed', fn (array $c): bool => $c['result']['evaluation_id'] === $evaluation->id)['calc'];
        $this->assertFalse($calc['fusion_applied']);
        // Sin fusión no se aplica ningún clamp en este paso: la confianza del
        // agente (1.0) se persiste solo redondeada, nunca recortada a 0.99.
        $this->assertNull($calc['confidence_clamp']);
        $this->assertNull($calc['risk_clamp']);
        $this->assertSame(1.0, $calc['base_confidence']);
        $this->assertSame(round($calc['base_confidence'], 2), $calc['confidence']);
        $this->assertSame($evaluation->fresh()->confidence_score, $calc['confidence']);
        $this->assertSame($calc['risk_after_agent'], $calc['risk_score']);
        $this->assertSame($evaluation->fresh()->risk_score, $calc['risk_score']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_confident_false_positive_is_reported_by_the_check(): void
    {
        $agent = app(EventEvaluationAgent::class);
        $agent->forcedClassification = EventClassification::FalsePositive;
        $agent->forcedConfidence = 0.9;

        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create([
            'team_id' => $user->currentTeam->id,
            'payload_normalized_json' => ['severity' => 'low'],
        ]);

        $evaluation = app(EvaluateEventWithAI::class)->execute($event);

        $ctx = $this->assertSystemLogged('ai.false_positive.checked');
        $this->assertSame('false_positive', $ctx['calc']['classification']);
        $this->assertGreaterThanOrEqual($ctx['calc']['threshold'], $ctx['calc']['confidence']);
        $this->assertTrue($ctx['result']['is_false_positive']);

        $priority = $this->assertSystemLogged('ai.priority.resolved');
        $this->assertFalse($priority['calc']['actionable']);
        $this->assertSame('low', $priority['result']['priority_level']);
        $this->assertSame($evaluation->priority_level->value, $priority['result']['priority_level']);
    }

    public function test_nothing_is_narrated_when_the_evaluation_transaction_rolls_back(): void
    {
        $this->app->instance(DetectFalsePositive::class, new class extends DetectFalsePositive
        {
            public function execute(AIEventEvaluation $evaluation): bool
            {
                throw new RuntimeException('boom');
            }
        });

        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create([
            'team_id' => $user->currentTeam->id,
            'payload_normalized_json' => ['severity' => 'high'],
        ]);

        try {
            app(EvaluateEventWithAI::class)->execute($event);
            $this->fail('La evaluación debía lanzar.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, AIEventEvaluation::count());
        $this->assertSystemNotLogged('ai.evaluation.completed');
        $this->assertSystemNotLogged('ai.priority.resolved');
        $this->assertSystemNotLogged('ai.false_positive.checked');
        $this->assertSystemNotLogged('ai.evaluation.rules_only');
    }

    public function test_operator_feedback_is_flagged_but_never_logged(): void
    {
        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create([
            'team_id' => $user->currentTeam->id,
            'payload_normalized_json' => ['severity' => 'high'],
        ]);

        app(EvaluateEventWithAI::class)->execute($event, operatorFeedback: ['verdicts' => [['note' => 'texto-operador-xyz']]]);

        $ctx = $this->assertSystemLogged('ai.evaluation.completed');
        $this->assertTrue($ctx['input']['operator_feedback_present']);

        $json = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('texto-operador-xyz', $json);
        $this->assertStringNotContainsString('Evaluación determinista', $json);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_explanation_is_always_created_for_evaluations(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'payload_normalized_json' => ['severity' => 'low'],
        ]);

        $evaluation = app(EvaluateEventWithAI::class)->execute($event);

        $explanation = AIExplanation::where('evaluation_id', $evaluation->id)->first();
        $this->assertNotNull($explanation);
        $this->assertNotEmpty($explanation->summary);
    }
}
