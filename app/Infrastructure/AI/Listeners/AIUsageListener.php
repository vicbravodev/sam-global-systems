<?php

namespace App\Infrastructure\AI\Listeners;

use App\Domains\AI\Models\AIConversationLink;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Support\SystemLog;
use Laravel\Ai\Events\AgentPrompted;

/**
 * Bridges Laravel AI SDK token-emitting events into our metered billing
 * pipeline. Listens to `AgentPrompted` (and, by extension, `AgentStreamed`
 * which extends it). Resolves the tenant via `ai_conversation_links` keyed
 * either by `response->conversationId` (when the calling agent uses the
 * Conversational interface) or by the per-call `invocationId`.
 *
 * If no link is registered we no-op (logged at debug as `ai.usage.not_metered`
 * / `no_conversation_link`) — tests for our domain wrappers
 * record their own usage events via `EvaluateEventWithAI` /
 * `EvaluateEventMultimodally`, so this listener is the canonical path for
 * any third-party SDK consumer that wires conversation linkage upfront.
 */
class AIUsageListener
{
    public function __construct(
        private readonly RecordUsageEvent $recordUsageEvent,
    ) {}

    public function handle(AgentPrompted $event): void
    {
        $link = $this->resolveLink($event);

        if ($link === null) {
            SystemLog::skipped(
                'ai.usage.not_metered',
                reason: 'no_conversation_link',
                input: ['invocation_id' => $event->invocationId],
                calc: ['conversation_id_present' => ($event->response->conversationId ?? null) !== null],
                debug: true,
            );

            return;
        }

        $usage = $event->response->usage;
        $invocationId = $event->invocationId;
        $logInput = ['team_id' => $link->team_id, 'invocation_id' => $invocationId];

        if ((int) $usage->promptTokens > 0) {
            if (UsageMeter::where('code', 'ai_tokens_in')->exists()) {
                $this->recordUsageEvent->execute(
                    teamId: $link->team_id,
                    meterCode: 'ai_tokens_in',
                    quantity: (int) $usage->promptTokens,
                    eventKey: 'ai_tokens_in:sdk:'.$invocationId,
                    metadata: [
                        'agent_conversation_id' => $link->agent_conversation_id,
                        'invocation_id' => $invocationId,
                    ],
                );
            } else {
                $this->logMeterMissing($logInput, 'in', 'ai_tokens_in', (int) $usage->promptTokens);
            }
        } else {
            SystemLog::skipped('ai.usage.not_metered', reason: 'zero_tokens', input: $logInput, calc: ['direction' => 'in'], debug: true);
        }

        if ((int) $usage->completionTokens > 0) {
            if (UsageMeter::where('code', 'ai_tokens_out')->exists()) {
                $this->recordUsageEvent->execute(
                    teamId: $link->team_id,
                    meterCode: 'ai_tokens_out',
                    quantity: (int) $usage->completionTokens,
                    eventKey: 'ai_tokens_out:sdk:'.$invocationId,
                    metadata: [
                        'agent_conversation_id' => $link->agent_conversation_id,
                        'invocation_id' => $invocationId,
                    ],
                );
            } else {
                $this->logMeterMissing($logInput, 'out', 'ai_tokens_out', (int) $usage->completionTokens);
            }
        } else {
            SystemLog::skipped('ai.usage.not_metered', reason: 'zero_tokens', input: $logInput, calc: ['direction' => 'out'], debug: true);
        }
    }

    /**
     * Tokens consumidos que no se cobran porque falta el meter: hueco de cobro.
     *
     * @param  array<string, mixed>  $logInput
     */
    private function logMeterMissing(array $logInput, string $direction, string $meterCode, int $tokens): void
    {
        SystemLog::degraded('ai.usage.not_metered', reason: 'meter_missing', input: $logInput, calc: [
            'direction' => $direction,
            'meter_code' => $meterCode,
            'tokens' => $tokens,
        ]);
    }

    private function resolveLink(AgentPrompted $event): ?AIConversationLink
    {
        $candidates = array_values(array_filter([
            $event->response->conversationId ?? null,
            $event->invocationId,
        ]));

        if ($candidates === []) {
            return null;
        }

        return AIConversationLink::withoutGlobalScopes()
            ->whereIn('agent_conversation_id', $candidates)
            ->first();
    }
}
