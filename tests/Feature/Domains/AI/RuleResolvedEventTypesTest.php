<?php

namespace Tests\Feature\Domains\AI;

use App\Contracts\AI\EventEvaluationAgent;
use App\Domains\AI\Actions\EvaluateEventWithAI;
use App\Domains\AI\Data\AIEvaluationResult;
use App\Domains\AI\Data\AIInputContext;
use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * Tipos cuyo hecho ya lo establece una regla determinista de SAM (p. ej.
 * `after_hours_movement`: se movió fuera del horario que configuró el
 * cliente). Preguntarle a un modelo si es real o falso positivo está mal
 * planteado y se paga: lo resuelve el motor de reglas como evento real, y
 * aun así hay evaluación, decisión e incidente.
 */
class RuleResolvedEventTypesTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(EventEvaluationAgent::class, new class implements EventEvaluationAgent
        {
            public function evaluate(AIInputContext $context): AIEvaluationResult
            {
                throw new RuntimeException('El agente de IA no debe llamarse para un tipo resuelto por regla.');
            }
        });
    }

    private function eventOfType(string $code): NormalizedEvent
    {
        $team = User::factory()->create()->currentTeam;
        $type = EventType::factory()->create(['code' => $code]);

        return NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'event_type_id' => $type->id,
            'payload_normalized_json' => ['severity' => 'high'],
        ]);
    }

    public function test_after_hours_movement_is_a_real_event_resolved_without_ai(): void
    {
        Event::fake([AIEvaluationCompleted::class]);
        $event = $this->eventOfType('after_hours_movement');

        $evaluation = app(EvaluateEventWithAI::class)->execute($event);

        $this->assertSame(EvaluationMode::RulesOnly, $evaluation->evaluation_mode);
        $this->assertSame(EventClassification::RealEvent, $evaluation->classification);
        $this->assertSame('rules_engine:1.0', $evaluation->model_used);
        $this->assertSame('rule_resolved_type:after_hours_movement', $evaluation->signals_json['key_factors']['rule_reason']);
        $this->assertStringContainsString('fuera del horario', (string) $evaluation->explanation_text);
        // El motor de decisiones corre sobre este evento: no se pierde la alerta.
        Event::assertDispatched(AIEvaluationCompleted::class, fn (AIEvaluationCompleted $e) => $e->evaluation->is($evaluation));
        $this->assertSystemLogged('ai.heuristics.evaluated', fn (array $c) => $c['result']['rule'] === 'rule_resolved_type');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_other_types_still_go_to_the_ai_agent(): void
    {
        $event = $this->eventOfType('unauthorized_passenger');

        $evaluation = app(EvaluateEventWithAI::class)->execute($event);

        // El agente espía lanza: la evaluación cae a rules_only por agent_error,
        // lo que prueba que sí se intentó la IA.
        $this->assertSame(EvaluationMode::RulesOnly, $evaluation->evaluation_mode);
        $this->assertSame('agent_error_fallback', $evaluation->signals_json['reasoning_steps'][0]);
    }
}
