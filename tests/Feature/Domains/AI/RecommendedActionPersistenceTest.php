<?php

namespace Tests\Feature\Domains\AI;

use App\Contracts\AI\EventEvaluationAgent;
use App\Contracts\NullImplementations\NullEventEvaluationAgent;
use App\Domains\AI\Actions\EvaluateEventWithAI;
use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\EvaluationPriority;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\AI\Models\AIInferenceLog;
use App\Domains\AI\Support\RecommendedActionText;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Infrastructure\AI\Agents\EventClassifierAgent;
use App\Infrastructure\AI\Agents\SdkEventEvaluationAgent;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * Toda evaluación persiste una acción recomendada concreta en español: la
 * del modelo cuando la da, o una determinista en las rutas sin IA.
 */
class RecommendedActionPersistenceTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AIMeterSeeder::class);

        // Solo interesa la evaluación: la creación de incidentes río abajo
        // tiene sus propios tests.
        Event::fake([AIEvaluationCompleted::class]);
    }

    public function test_classifier_schema_requires_recommended_action(): void
    {
        $schema = (new EventClassifierAgent)->schema(new JsonSchemaTypeFactory);

        $this->assertArrayHasKey('recommended_action', $schema);
        $this->assertStringContainsString('recommended_action', (string) (new EventClassifierAgent)->instructions());
    }

    public function test_ai_evaluation_persists_the_model_recommended_action(): void
    {
        $this->app->instance(EventEvaluationAgent::class, app(SdkEventEvaluationAgent::class));

        EventClassifierAgent::fake([[
            'classification' => 'real_event',
            'confidence_score' => 0.9,
            'risk_score_delta' => 0.2,
            'explanation_summary' => 'Pánico en carretera con frenado brusco previo.',
            'reasoning_steps' => ['Frenado brusco 2 min antes'],
            'key_factors' => [['name' => 'harsh_driving_near_event', 'value' => 'true']],
            'recommended_action' => '  Llamar al operador y despachar apoyo a la última ubicación conocida.  ',
        ]]);

        $event = $this->panicEvent($this->team());

        $evaluation = app(EvaluateEventWithAI::class)->execute($event);

        $this->assertSame(EvaluationMode::AiText, $evaluation->evaluation_mode);
        $this->assertSame('Llamar al operador y despachar apoyo a la última ubicación conocida.', $evaluation->fresh()->recommended_action);

        $log = AIInferenceLog::query()->where('evaluation_id', $evaluation->id)->firstOrFail();
        $this->assertSame(RecommendedActionText::SOURCE_AGENT, $log->output_json['recommended_action_source']);

        $completed = $this->assertSystemLogged('ai.evaluation.completed');
        $this->assertSame(RecommendedActionText::SOURCE_AGENT, $completed['result']['recommended_action_source']);
        $this->assertStringNotContainsString('despachar apoyo', json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_ai_response_without_recommended_action_falls_back_to_deterministic_text(): void
    {
        $this->app->instance(EventEvaluationAgent::class, app(SdkEventEvaluationAgent::class));

        EventClassifierAgent::fake([[
            'classification' => 'real_event',
            'confidence_score' => 0.9,
            'risk_score_delta' => 0.0,
            'explanation_summary' => 'Pánico real.',
            'reasoning_steps' => [],
            'key_factors' => [],
            'recommended_action' => '   ',
        ]]);

        $evaluation = app(EvaluateEventWithAI::class)->execute($this->panicEvent($this->team()));

        $this->assertStringStartsWith('Llamar al operador de inmediato', (string) $evaluation->fresh()->recommended_action);

        $completed = $this->assertSystemLogged('ai.evaluation.completed');
        $this->assertSame(RecommendedActionText::SOURCE_DETERMINISTIC, $completed['result']['recommended_action_source']);
    }

    public function test_overlong_model_recommendation_is_truncated_to_the_column_length(): void
    {
        $this->app->instance(EventEvaluationAgent::class, app(SdkEventEvaluationAgent::class));

        EventClassifierAgent::fake([[
            'classification' => 'real_event',
            'confidence_score' => 0.9,
            'risk_score_delta' => 0.0,
            'explanation_summary' => 'Pánico real.',
            'reasoning_steps' => [],
            'key_factors' => [],
            'recommended_action' => str_repeat('Llamar al operador. ', 40),
        ]]);

        $evaluation = app(EvaluateEventWithAI::class)->execute($this->panicEvent($this->team()));

        $this->assertLessThanOrEqual(RecommendedActionText::MAX_LENGTH, mb_strlen((string) $evaluation->fresh()->recommended_action));
    }

    public function test_agent_failure_rules_only_fallback_persists_a_deterministic_recommendation(): void
    {
        $agent = app(EventEvaluationAgent::class);
        $this->assertInstanceOf(NullEventEvaluationAgent::class, $agent);
        $agent->shouldFail = true;

        $evaluation = app(EvaluateEventWithAI::class)->execute($this->panicEvent($this->team()));

        $this->assertSame(EvaluationMode::RulesOnly, $evaluation->evaluation_mode);
        $this->assertSame(EventClassification::Unclear, $evaluation->classification);
        $this->assertSame(
            'Llamar al operador de inmediato para confirmar su estado y despachar apoyo a la última ubicación conocida si no responde o confirma la emergencia.',
            $evaluation->fresh()->recommended_action,
        );

        $completed = $this->assertSystemLogged('ai.evaluation.completed');
        $this->assertSame(RecommendedActionText::SOURCE_DETERMINISTIC, $completed['result']['recommended_action_source']);
    }

    public function test_quota_exceeded_rules_only_persists_a_deterministic_recommendation(): void
    {
        $team = $this->team();

        UsageEvent::create([
            'team_id' => $team->id,
            'usage_meter_id' => UsageMeter::where('code', 'ai_tokens_in')->firstOrFail()->id,
            'event_key' => 'seed:over-quota',
            'quantity' => 6_000_000,
            'occurred_at' => now(),
            'billing_period_key' => now()->format('Y-m'),
        ]);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'payload_normalized_json' => ['severity' => 'high'],
        ]);

        $evaluation = app(EvaluateEventWithAI::class)->execute($event);

        $this->assertSame(EvaluationMode::RulesOnly, $evaluation->evaluation_mode);
        $this->assertNotEmpty($evaluation->fresh()->recommended_action);
    }

    public function test_heuristic_false_positive_recommends_discarding(): void
    {
        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->team()->id,
            'payload_normalized_json' => ['severity' => 'low', 'signature' => 'heartbeat'],
        ]);

        $evaluation = app(EvaluateEventWithAI::class)->execute($event);

        $this->assertSame(EventClassification::FalsePositive, $evaluation->classification);
        $this->assertStringStartsWith('Descartar como falsa alarma', (string) $evaluation->fresh()->recommended_action);
    }

    public function test_deterministic_recommendation_covers_every_classification_and_priority(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->team()->id]);

        foreach (EventClassification::cases() as $classification) {
            foreach (EvaluationPriority::cases() as $priority) {
                $text = RecommendedActionText::deterministic($event, $classification, $priority);

                $this->assertNotSame('', trim($text), $classification->value.'/'.$priority->value);
                $this->assertLessThanOrEqual(RecommendedActionText::MAX_LENGTH, mb_strlen($text));
            }
        }
    }

    private function team(): Team
    {
        return User::factory()->create()->currentTeam;
    }

    private function panicEvent(Team $team): NormalizedEvent
    {
        $category = EventCategory::query()->firstOrCreate(['code' => 'emergency'], EventCategory::factory()->raw(['code' => 'emergency']));
        $severity = EventSeverity::query()->firstOrCreate(['code' => 'critical'], EventSeverity::factory()->raw(['code' => 'critical', 'level' => 4]));
        $type = EventType::query()->firstOrCreate(
            ['code' => 'panic_button'],
            EventType::factory()->raw(['code' => 'panic_button', 'name' => 'Botón de pánico', 'category_id' => $category->id, 'default_severity_id' => $severity->id]),
        );

        return NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'event_type_id' => $type->id,
            'event_category_id' => $category->id,
            'event_severity_id' => $severity->id,
            'payload_normalized_json' => ['description' => 'Pánico'],
        ]);
    }
}
