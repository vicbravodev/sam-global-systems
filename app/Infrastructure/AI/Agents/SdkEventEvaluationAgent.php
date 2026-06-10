<?php

namespace App\Infrastructure\AI\Agents;

use App\Contracts\AI\EventEvaluationAgent;
use App\Domains\AI\Data\AIEvaluationResult;
use App\Domains\AI\Data\AIInputContext;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Support\ModelPricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Responses\AgentResponse;
use RuntimeException;
use Throwable;

/**
 * Production `EventEvaluationAgent` backed by the Laravel AI SDK
 * (`composer require laravel/ai`). Bound by `AIServiceProvider` whenever
 * `config('ai.default')` resolves to a configured provider; otherwise the
 * Null implementation continues to handle the contract.
 */
class SdkEventEvaluationAgent implements EventEvaluationAgent
{
    public function __construct(
        private readonly EventClassifierAgent $classifier,
        private readonly ModelPricing $pricing,
    ) {}

    public function evaluate(AIInputContext $context): AIEvaluationResult
    {
        $payload = json_encode($context->toArray(), JSON_THROW_ON_ERROR);

        $startedAt = hrtime(true);

        try {
            $response = $this->classifier->prompt($payload);
        } catch (Throwable $exception) {
            throw new RuntimeException('Laravel AI SDK invocation failed: '.$exception->getMessage(), previous: $exception);
        }

        $latencyMs = (int) intdiv(hrtime(true) - $startedAt, 1_000_000);

        $structured = $this->parseStructuredResponse($response->text);

        $this->persistConversationLink($context, $response);

        // Pricing keys on the raw provider model id; when `meta` is absent
        // the cost resolves to 0.0 rather than failing the evaluation.
        return new AIEvaluationResult(
            classification: EventClassification::from($structured['classification']),
            confidenceScore: (float) $structured['confidence_score'],
            riskScoreDelta: (float) ($structured['risk_score_delta'] ?? 0.0),
            explanationSummary: (string) ($structured['explanation_summary'] ?? ''),
            reasoningSteps: array_values(array_map('strval', $structured['reasoning_steps'] ?? [])),
            keyFactors: (array) ($structured['key_factors'] ?? []),
            modelUsed: 'laravel-ai-sdk:'.($response->meta?->model ?? 'event-classifier'),
            inputTokens: (int) $response->usage->promptTokens,
            outputTokens: (int) $response->usage->completionTokens,
            latencyMs: $latencyMs,
            costEstimate: $this->pricing->estimateCost(
                $response->meta?->model,
                (int) $response->usage->promptTokens,
                (int) $response->usage->completionTokens,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parseStructuredResponse(string $text): array
    {
        $trimmed = trim($text);

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($trimmed, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('SDK response was not valid JSON: '.$exception->getMessage(), previous: $exception);
        }

        if (! isset($decoded['classification'], $decoded['confidence_score'])) {
            throw new RuntimeException('SDK response missing required fields (classification, confidence_score)');
        }

        return $decoded;
    }

    private function persistConversationLink(AIInputContext $context, AgentResponse $response): void
    {
        $conversationId = $response->conversationId ?? $response->invocationId;

        if ($conversationId === null) {
            return;
        }

        if (! Schema::hasTable('ai_conversation_links')) {
            return;
        }

        // The Laravel AI SDK only writes to `agent_conversations` for agents
        // that opt into the `Conversational` middleware. Our event-evaluation
        // wrapper is one-shot, so we materialize a placeholder row for the
        // FK to point at; tests in environments without the SDK migration
        // simply skip this step.
        if (Schema::hasTable('agent_conversations')) {
            DB::table('agent_conversations')->insertOrIgnore([
                'id' => $conversationId,
                'user_id' => null,
                'title' => 'event_evaluation:'.$context->normalizedEventId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('ai_conversation_links')->upsert(
            [[
                'team_id' => $context->teamId,
                'user_id' => null,
                'agent_conversation_id' => $conversationId,
                'normalized_event_id' => $context->normalizedEventId,
                'evaluation_id' => null,
                'purpose' => 'event_evaluation',
                'metadata_json' => json_encode([
                    'invocation_id' => $response->invocationId,
                    'agent' => EventClassifierAgent::class,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            uniqueBy: ['team_id', 'agent_conversation_id'],
            update: ['updated_at'],
        );
    }
}
