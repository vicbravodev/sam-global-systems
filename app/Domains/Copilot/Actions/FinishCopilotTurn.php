<?php

namespace App\Domains\Copilot\Actions;

use App\Domains\AI\Support\ModelPricing;
use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Copilot\Data\CopilotTurn;
use App\Domains\Copilot\Data\CopilotTurnOutcome;
use App\Domains\Copilot\Enums\CopilotMessageRole;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Support\SystemLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Last stage of a Copilot turn: stores the answer with what the collector
 * gathered (cards, sources, tool trail, facts digest, follow-ups), meters
 * it, audits it and narrates it in the system log. Callers run it inside
 * `TenantContext::for($team->id)`.
 */
class FinishCopilotTurn
{
    private const EMPTY_ANSWER = 'No pude completar la respuesta.';

    public function __construct(
        private readonly ModelPricing $pricing,
        private readonly RecordCopilotUsage $recordUsage,
        private readonly RecordAuditEntry $audit,
    ) {}

    public function execute(CopilotTurn $turn, CopilotTurnOutcome $outcome): CopilotMessage
    {
        $latencyMs = SystemLog::elapsedMs($turn->startedAt);
        $cost = $this->pricing->estimateUsageCost($outcome->model, $outcome->usage);
        $collector = $turn->collector;
        $tools = $collector->tools();
        $blocks = $collector->blocks();
        $followups = $collector->followupList();

        $reply = DB::transaction(function () use ($turn, $outcome, $latencyMs, $cost, $collector, $tools, $blocks, $followups): CopilotMessage {
            $reply = CopilotMessage::query()->create([
                'team_id' => $turn->team->id,
                'copilot_conversation_id' => $turn->conversation->id,
                'user_id' => null,
                'role' => CopilotMessageRole::Assistant,
                'content' => $outcome->text !== '' ? $outcome->text : self::EMPTY_ANSWER,
                'intent' => $outcome->intent,
                'channel' => $turn->channel,
                'context_json' => array_filter([
                    'resolved' => ['asset_id' => $collector->lastAssetId()],
                    'facts_digest' => $collector->factsDigest() ?: null,
                    'followups' => $followups ?: null,
                    'mode' => $outcome->mode,
                    'partial' => $outcome->partial ?: null,
                ], fn ($value) => $value !== null),
                'blocks_json' => $blocks,
                'tools_json' => $tools,
                'sources_json' => $collector->sources(),
                'model' => $outcome->model,
                'input_tokens' => $outcome->usage->inputTokens,
                'output_tokens' => $outcome->usage->outputTokens,
                'cost_estimate' => $cost,
                'latency_ms' => $latencyMs,
            ]);

            $turn->conversation->forceFill([
                'messages_count' => $turn->conversation->messages_count + 2,
                'last_message_at' => now(),
            ])->save();

            return $reply;
        });

        $this->recordUsage->execute($reply);

        $this->audit->execute(
            actorType: AuditActorType::User,
            actorId: (int) $turn->user->id,
            action: 'copilot.query',
            category: AuditCategory::Ai,
            entityType: CopilotMessage::class,
            entityId: (int) $reply->id,
            summary: 'Consulta a SAM Copilot: '.Str::limit((string) $turn->question->content, 120),
            teamId: $turn->team->id,
            metadata: [
                'intent' => $outcome->intent->value,
                'asset_id' => $collector->lastAssetId(),
                'tools' => array_column($tools, 'tool'),
                'channel' => $turn->channel,
                'mode' => $outcome->mode,
                'steps' => $outcome->steps,
                'input_tokens' => $outcome->usage->inputTokens,
                'output_tokens' => $outcome->usage->outputTokens,
            ],
            signature: 'copilot.query:'.$reply->id,
        );

        SystemLog::ok(
            'copilot.turn.completed',
            [
                'team_id' => $turn->team->id,
                'message_id' => $reply->id,
                'mode' => $outcome->mode,
                'partial' => $outcome->partial,
            ],
            calc: [
                'steps' => $outcome->steps,
                'tools' => array_column($tools, 'tool'),
                'tool_count' => count($tools),
                'blocks_count' => count($blocks),
                'followups_count' => count($followups),
            ],
            result: [
                'model' => $outcome->model,
                'input_tokens' => $outcome->usage->inputTokens,
                'cached_input_tokens' => $outcome->usage->cacheReadInputTokens,
                'output_tokens' => $outcome->usage->outputTokens,
                'cost_estimate' => $cost,
                'latency_ms' => $latencyMs,
                'first_token_ms' => $outcome->firstTokenMs,
            ],
            durationMs: $latencyMs,
        );

        return $reply;
    }
}
