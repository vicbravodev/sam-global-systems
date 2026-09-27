<?php

namespace Tests\Feature\Console;

use App\Contracts\AI\EventEvaluationAgent;
use App\Domains\AI\Data\AIEvaluationResult;
use App\Domains\AI\Data\AIInputContext;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Infrastructure\AI\Agents\EventClassifierAgent;
use App\Infrastructure\AI\Agents\SdkEventEvaluationAgent;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * Arnés `ai:eval-prompts`: sin red, con un agente falso bindeado o con el
 * agente del SDK en modo fake.
 */
class EvalAIPromptsCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Respuesta "correcta" por tipo de evento para los casos del repo.
     */
    private const GOLDEN = [
        'panic_button' => 'real_event',
        'collision' => 'real_event',
        'tampering' => 'real_event',
        'camera_obstructed' => 'unclear',
        'after_hours_movement' => 'real_event',
        'suspicious_stop' => 'real_event',
        'unauthorized_passenger' => 'real_event',
        'unmapped' => 'unclear',
        'speeding' => 'real_event',
    ];

    /**
     * @param  array<string, string>  $overrides  event_type_code => clasificación
     */
    private function bindAgent(array $overrides = []): void
    {
        $map = [...self::GOLDEN, ...$overrides];

        $this->app->instance(EventEvaluationAgent::class, new class($map, $overrides) implements EventEvaluationAgent
        {
            /**
             * @param  array<string, string>  $map
             * @param  array<string, string>  $overridden
             */
            public function __construct(private array $map, private array $overridden) {}

            public function evaluate(AIInputContext $context): AIEvaluationResult
            {
                $code = $context->normalizedEvent['payload']['event_type_code'] ?? 'unmapped';

                // Pánico resuelto y estacionado en base: el único falso positivo legítimo.
                $benign = ($context->contextSignals['external_resolved'] ?? false)
                    && ($context->contextSignals['parked_at_base'] ?? false);
                $classification = $benign && ! isset($this->overridden[$code])
                    ? 'false_positive'
                    : ($this->map[$code] ?? 'unclear');

                return new AIEvaluationResult(
                    classification: EventClassification::from($classification),
                    confidenceScore: 0.8,
                    riskScoreDelta: 0.0,
                    explanationSummary: 'Caso de prueba.',
                    reasoningSteps: [],
                    keyFactors: [],
                    modelUsed: 'fake',
                    inputTokens: 100,
                    outputTokens: 20,
                    latencyMs: 250,
                    costEstimate: 0.001,
                );
            }
        });
    }

    public function test_repo_fixtures_are_valid_and_cover_at_least_twelve_cases(): void
    {
        $files = File::glob(base_path('tests/Fixtures/ai-eval/cases/*.json'));

        $this->assertGreaterThanOrEqual(12, count($files));

        $this->bindAgent();

        $this->artisan('ai:eval-prompts')
            ->expectsOutputToContain(sprintf('Precisión: 100.0%% (%1$d/%1$d)', count($files)))
            ->expectsOutputToContain('Falsos negativos en casos críticos: 0')
            ->expectsOutputToContain('Latencia promedio: 250 ms')
            ->expectsOutputToContain(sprintf('Tokens totales: %d', count($files) * 120))
            ->assertSuccessful();
    }

    public function test_downgrading_a_critical_panic_is_a_critical_false_negative_and_fails_threshold(): void
    {
        // Todos los pánicos como falso positivo: el del estacionado en base
        // está permitido, los de carretera/amenaza no.
        $this->bindAgent(['panic_button' => 'false_positive']);

        $this->artisan('ai:eval-prompts', ['--fail-under' => '0.9'])
            ->expectsOutputToContain('Falsos negativos en casos críticos: 4')
            ->expectsOutputToContain('por debajo del umbral')
            ->assertFailed();
    }

    public function test_filter_runs_a_subset(): void
    {
        $this->bindAgent();

        $this->artisan('ai:eval-prompts', ['--filter' => '13-exceso'])
            ->expectsOutputToContain('Precisión: 100.0% (1/1)')
            ->assertSuccessful();
    }

    public function test_invalid_threshold_and_missing_directory_are_rejected(): void
    {
        $this->bindAgent();

        $this->artisan('ai:eval-prompts', ['--fail-under' => '2'])->assertExitCode(2);
        $this->artisan('ai:eval-prompts', ['--cases' => base_path('tests/Fixtures/ai-eval/nope')])->assertExitCode(2);
    }

    public function test_runs_through_the_sdk_agent_without_network_and_leaves_no_rows(): void
    {
        $team = Team::factory()->create();
        NormalizedEvent::factory()->create(['team_id' => $team->id]);
        $this->app->instance(EventEvaluationAgent::class, app(SdkEventEvaluationAgent::class));

        EventClassifierAgent::fake([
            new TextResponse(
                json_encode([
                    'classification' => 'real_event',
                    'confidence_score' => 0.93,
                    'risk_score_delta' => 0.1,
                    'explanation_summary' => 'Exceso de velocidad: 128 km/h en zona de 90 km/h.',
                    'reasoning_steps' => ['Velocidad sostenida muy por encima del límite.'],
                    'key_factors' => ['speed_kph' => 128],
                ], JSON_THROW_ON_ERROR),
                new Usage(promptTokens: 900, completionTokens: 80),
                new Meta(provider: 'openai', model: 'gpt-test'),
            ),
        ]);

        $this->artisan('ai:eval-prompts', ['--filter' => '13-exceso', '--fail-under' => '0.9'])
            ->expectsOutputToContain('Precisión: 100.0% (1/1)')
            ->expectsOutputToContain('Tokens totales: 980')
            ->assertSuccessful();

        $this->assertDatabaseCount('ai_conversation_links', 0);
    }
}
