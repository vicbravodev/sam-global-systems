<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Data\AIInputContext;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Models\AIConversationLink;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Infrastructure\AI\Agents\EventClassifierAgent;
use App\Infrastructure\AI\Agents\SdkEventEvaluationAgent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

class EvaluateEventViaSdkTest extends TestCase
{
    use RefreshDatabase;

    public function test_wrapper_parses_structured_json_response_into_evaluation_result(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        EventClassifierAgent::fake([
            new TextResponse(
                json_encode([
                    'classification' => 'real_event',
                    'confidence_score' => 0.91,
                    'risk_score_delta' => 0.12,
                    'explanation_summary' => 'High-severity collision signature with corroborating context.',
                    'reasoning_steps' => ['severity_high', 'recent_event_count_low'],
                    'key_factors' => ['severity' => 'high'],
                ], JSON_THROW_ON_ERROR),
                new TextUsage(inputTokens: 320, outputTokens: 95),
                new Meta(provider: 'openai', model: 'gpt-test'),
            ),
        ]);

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);

        $input = new AIInputContext(
            teamId: $team->id,
            normalizedEventId: $event->id,
            normalizedEvent: ['severity' => 'high'],
            contextSignals: ['signature' => 'crash'],
            operationalProfile: ['risk_level' => 'high'],
            recentHistory: ['event_count' => 1],
            tenantProfile: ['automation_level' => 'auto'],
        );

        $result = app(SdkEventEvaluationAgent::class)->evaluate($input);

        $this->assertSame(EventClassification::RealEvent, $result->classification);
        $this->assertSame(0.91, $result->confidenceScore);
        $this->assertSame(0.12, $result->riskScoreDelta);
        $this->assertSame(320, $result->inputTokens);
        $this->assertSame(95, $result->outputTokens);
        $this->assertStringStartsWith('laravel-ai-sdk:', $result->modelUsed);
        $this->assertContains('severity_high', $result->reasoningSteps);
    }

    public function test_wrapper_measures_latency_and_estimates_cost(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        config()->set('ai.pricing', [
            'gpt-test' => ['input' => 2.0, 'output' => 8.0],
        ]);

        EventClassifierAgent::fake([
            new TextResponse(
                json_encode([
                    'classification' => 'real_event',
                    'confidence_score' => 0.9,
                    'risk_score_delta' => 0.1,
                    'explanation_summary' => 'Confirmed.',
                    'reasoning_steps' => [],
                    'key_factors' => [],
                ], JSON_THROW_ON_ERROR),
                new TextUsage(inputTokens: 500_000, outputTokens: 250_000),
                new Meta(provider: 'openai', model: 'gpt-test'),
            ),
        ]);

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);

        $result = app(SdkEventEvaluationAgent::class)->evaluate(new AIInputContext(
            teamId: $team->id,
            normalizedEventId: $event->id,
            normalizedEvent: [],
            contextSignals: [],
            operationalProfile: [],
            recentHistory: [],
            tenantProfile: [],
        ));

        $this->assertSame(3.0, $result->costEstimate);
        $this->assertGreaterThanOrEqual(0, $result->latencyMs);
    }

    public function test_model_without_pricing_entry_costs_zero(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        config()->set('ai.pricing', []);

        EventClassifierAgent::fake([
            new TextResponse(
                json_encode([
                    'classification' => 'unclear',
                    'confidence_score' => 0.4,
                ], JSON_THROW_ON_ERROR),
                new TextUsage(inputTokens: 320, outputTokens: 95),
                new Meta(provider: 'openai', model: 'gpt-unpriced'),
            ),
        ]);

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);

        $result = app(SdkEventEvaluationAgent::class)->evaluate(new AIInputContext(
            teamId: $team->id,
            normalizedEventId: $event->id,
            normalizedEvent: [],
            contextSignals: [],
            operationalProfile: [],
            recentHistory: [],
            tenantProfile: [],
        ));

        $this->assertSame(0.0, $result->costEstimate);
    }

    public function test_wrapper_persists_conversation_link_after_call(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        EventClassifierAgent::fake([
            json_encode([
                'classification' => 'unclear',
                'confidence_score' => 0.4,
                'risk_score_delta' => 0.0,
                'explanation_summary' => 'Insufficient evidence.',
                'reasoning_steps' => [],
                'key_factors' => [],
            ], JSON_THROW_ON_ERROR),
        ]);

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);

        $input = new AIInputContext(
            teamId: $team->id,
            normalizedEventId: $event->id,
            normalizedEvent: [],
            contextSignals: [],
            operationalProfile: [],
            recentHistory: [],
            tenantProfile: [],
        );

        app(SdkEventEvaluationAgent::class)->evaluate($input);

        $link = AIConversationLink::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('normalized_event_id', $event->id)
            ->first();

        $this->assertNotNull($link, 'Expected wrapper to persist an AIConversationLink');
        $this->assertSame('event_evaluation', $link->purpose);
        $this->assertNotEmpty($link->agent_conversation_id);
    }

    public function test_wrapper_throws_when_response_is_not_valid_json(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        EventClassifierAgent::fake(['this is not json']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/SDK response was not valid JSON/');

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);

        app(SdkEventEvaluationAgent::class)->evaluate(new AIInputContext(
            teamId: $team->id,
            normalizedEventId: $event->id,
            normalizedEvent: [],
            contextSignals: [],
            operationalProfile: [],
            recentHistory: [],
            tenantProfile: [],
        ));
    }

    public function test_wrapper_throws_when_required_fields_missing(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        EventClassifierAgent::fake([
            json_encode(['only_random' => 'fields'], JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing required fields/');

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);

        app(SdkEventEvaluationAgent::class)->evaluate(new AIInputContext(
            teamId: $team->id,
            normalizedEventId: $event->id,
            normalizedEvent: [],
            contextSignals: [],
            operationalProfile: [],
            recentHistory: [],
            tenantProfile: [],
        ));
    }

    public function test_classifier_declares_structured_output_schema(): void
    {
        $agent = new EventClassifierAgent;

        $this->assertInstanceOf(HasStructuredOutput::class, $agent);

        $schema = $agent->schema(new JsonSchemaTypeFactory);

        $this->assertSame(
            ['classification', 'confidence_score', 'risk_score_delta', 'explanation_summary', 'reasoning_steps', 'key_factors'],
            array_keys($schema),
        );
    }

    public function test_wrapper_consumes_native_structured_response(): void
    {
        EventClassifierAgent::fake([[
            'classification' => 'real_event',
            'confidence_score' => 0.8,
            'risk_score_delta' => 0.2,
            'explanation_summary' => 'Pánico en carretera con frenado brusco previo.',
            'reasoning_steps' => ['Frenado brusco 2 min antes'],
            'key_factors' => [['name' => 'harsh_driving_near_event', 'value' => 'true']],
        ]]);

        $result = app(SdkEventEvaluationAgent::class)->evaluate($this->emptyContext());

        $this->assertSame(EventClassification::RealEvent, $result->classification);
        $this->assertSame(0.8, $result->confidenceScore);
        $this->assertSame(['harsh_driving_near_event' => 'true'], $result->keyFactors);
    }

    public function test_wrapper_strips_markdown_fences_from_text_response(): void
    {
        EventClassifierAgent::fake([
            "Aquí está:\n```json\n".json_encode([
                'classification' => 'false_positive',
                'confidence_score' => 0.7,
                'risk_score_delta' => -0.2,
            ], JSON_THROW_ON_ERROR)."\n```",
        ]);

        $result = app(SdkEventEvaluationAgent::class)->evaluate($this->emptyContext());

        $this->assertSame(EventClassification::FalsePositive, $result->classification);
        $this->assertSame(-0.2, $result->riskScoreDelta);
    }

    public function test_wrapper_treats_confidence_above_one_as_percentage_and_clamps_delta(): void
    {
        EventClassifierAgent::fake([
            json_encode([
                'classification' => 'real_event',
                'confidence_score' => 85,
                'risk_score_delta' => 3.5,
            ], JSON_THROW_ON_ERROR),
        ]);

        $result = app(SdkEventEvaluationAgent::class)->evaluate($this->emptyContext());

        $this->assertSame(0.85, $result->confidenceScore);
        $this->assertSame(1.0, $result->riskScoreDelta);
    }

    public function test_wrapper_clamps_out_of_range_confidence(): void
    {
        EventClassifierAgent::fake([
            json_encode(['classification' => 'real_event', 'confidence_score' => 250, 'risk_score_delta' => -4], JSON_THROW_ON_ERROR),
        ]);

        $result = app(SdkEventEvaluationAgent::class)->evaluate($this->emptyContext());

        $this->assertSame(1.0, $result->confidenceScore);
        $this->assertSame(-1.0, $result->riskScoreDelta);
    }

    public function test_unknown_classification_falls_back_to_unclear(): void
    {
        EventClassifierAgent::fake([
            json_encode(['classification' => 'robbery_confirmed', 'confidence_score' => 0.9], JSON_THROW_ON_ERROR),
        ]);

        $result = app(SdkEventEvaluationAgent::class)->evaluate($this->emptyContext());

        $this->assertSame(EventClassification::Unclear, $result->classification);
    }

    private function emptyContext(): AIInputContext
    {
        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create(['team_id' => $user->currentTeam->id]);

        return new AIInputContext(
            teamId: $user->currentTeam->id,
            normalizedEventId: $event->id,
            normalizedEvent: [],
            contextSignals: [],
            operationalProfile: [],
            recentHistory: [],
            tenantProfile: [],
        );
    }
}
