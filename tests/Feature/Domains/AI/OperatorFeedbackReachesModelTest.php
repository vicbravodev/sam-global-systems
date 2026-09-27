<?php

namespace Tests\Feature\Domains\AI;

use App\Contracts\AI\EventEvaluationAgent;
use App\Domains\AI\Actions\EvaluateEventWithAI;
use App\Domains\AI\Actions\ReevaluateEventWithNewEvidence;
use App\Domains\AI\Data\AIEvaluationResult;
use App\Domains\AI\Data\AIInputContext;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Enums\OperatorVerdict;
use App\Domains\AI\Enums\ReevaluationStatus;
use App\Domains\AI\Enums\ReevaluationTrigger;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIInferenceLog;
use App\Domains\AI\Models\AIReevaluationRequest;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El diálogo "SAM volverá a evaluar el evento con tu feedback" tiene que
 * llegar de verdad al agente: el motivo y los veredictos del operador viajan
 * en `recent_history.operator_feedback` del input.
 */
class OperatorFeedbackReachesModelTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<AIInputContext> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AIMeterSeeder::class);

        $captured = &$this->captured;
        $this->app->instance(EventEvaluationAgent::class, new class($captured) implements EventEvaluationAgent
        {
            /** @param list<AIInputContext> $captured */
            public function __construct(private array &$captured) {}

            public function evaluate(AIInputContext $context): AIEvaluationResult
            {
                $this->captured[] = $context;

                return new AIEvaluationResult(
                    classification: EventClassification::RealEvent,
                    confidenceScore: 0.8,
                    riskScoreDelta: 0.0,
                    explanationSummary: 'Evaluación capturada.',
                    reasoningSteps: ['captured'],
                    keyFactors: [],
                    modelUsed: 'capturing-agent',
                    inputTokens: 10,
                    outputTokens: 5,
                    latencyMs: 12,
                    costEstimate: 0.0,
                );
            }
        });
    }

    private function eventFor(Team $team): NormalizedEvent
    {
        return NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'payload_normalized_json' => ['severity' => 'high'],
        ]);
    }

    public function test_manual_feedback_and_operator_verdicts_reach_the_agent(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $event = $this->eventFor($team);

        AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            'evaluation_version' => 1,
            'classification' => EventClassification::FalsePositive,
            'operator_verdict' => OperatorVerdict::Confirmed,
            'operator_verdict_by' => $user->id,
            'operator_verdict_at' => now()->subHour(),
            'operator_verdict_note' => 'El conductor confirmó el asalto por radio.',
        ]);

        // Cuatro motivos previos: sólo viajan los 3 más recientes (el actual incluido).
        foreach (['más antiguo', 'segundo', 'tercero'] as $i => $reason) {
            AIReevaluationRequest::factory()->create([
                'normalized_event_id' => $event->id,
                'trigger_type' => ReevaluationTrigger::ManualReviewRequested,
                'reason' => $reason,
                'status' => ReevaluationStatus::Completed,
                'requested_at' => now()->subMinutes(30 - $i),
            ]);
        }

        app(ReevaluateEventWithNewEvidence::class)->execute(
            event: $event,
            trigger: ReevaluationTrigger::ManualReviewRequested,
            reason: 'La unidad estaba en carretera, no en base.',
        );

        $this->assertCount(1, $this->captured);
        $feedback = $this->captured[0]->recentHistory['operator_feedback'] ?? null;
        $this->assertIsArray($feedback);

        $this->assertSame(
            ['La unidad estaba en carretera, no en base.', 'tercero', 'segundo'],
            array_column($feedback['manual_feedback'], 'reason'),
        );
        $this->assertNotNull($feedback['manual_feedback'][0]['requested_at']);

        $this->assertCount(1, $feedback['operator_verdicts']);
        $this->assertSame('confirmed', $feedback['operator_verdicts'][0]['verdict']);
        $this->assertSame('false_positive', $feedback['operator_verdicts'][0]['ai_classification']);
        $this->assertSame('El conductor confirmó el asalto por radio.', $feedback['operator_verdicts'][0]['note']);

        // Sin PII: nada de ids ni nombres de usuario en lo que ve el modelo.
        $encoded = json_encode($this->captured[0]->toArray());
        $this->assertStringNotContainsString($user->email, $encoded);
        $this->assertStringNotContainsString('operator_verdict_by', $encoded);

        // Queda auditado en el snapshot del inference log.
        $log = AIInferenceLog::query()->latest('id')->firstOrFail();
        $this->assertSame(
            'La unidad estaba en carretera, no en base.',
            $log->input_snapshot_json['recent_history']['operator_feedback']['manual_feedback'][0]['reason'],
        );
    }

    public function test_first_evaluation_without_feedback_leaves_input_untouched(): void
    {
        $team = User::factory()->create()->currentTeam;
        $event = $this->eventFor($team);

        app(EvaluateEventWithAI::class)->execute($event);

        $this->assertCount(1, $this->captured);
        $this->assertArrayNotHasKey('operator_feedback', $this->captured[0]->recentHistory);
    }

    public function test_feedback_of_another_tenant_never_reaches_the_agent(): void
    {
        $team = User::factory()->create()->currentTeam;
        $other = Team::factory()->create();
        $event = $this->eventFor($team);

        // Evaluación etiquetada de OTRO tenant apuntando al mismo evento
        // (dato corrupto): no debe colarse en el input.
        AIEventEvaluation::factory()->create([
            'team_id' => $other->id,
            'normalized_event_id' => $event->id,
            'operator_verdict' => OperatorVerdict::FalsePositive,
            'operator_verdict_at' => now(),
        ]);

        app(ReevaluateEventWithNewEvidence::class)->execute(
            event: $event,
            trigger: ReevaluationTrigger::MediaArrived,
        );

        $this->assertArrayNotHasKey('operator_feedback', $this->captured[0]->recentHistory);
    }
}
