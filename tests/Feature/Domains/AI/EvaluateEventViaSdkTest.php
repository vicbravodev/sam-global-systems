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
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
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
                new Usage(promptTokens: 320, completionTokens: 95),
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
}
