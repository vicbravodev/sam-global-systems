<?php

namespace App\Domains\Copilot\Streaming;

use App\Domains\Copilot\Data\CopilotTurnOutcome;
use App\Domains\Copilot\Support\CopilotTurnCollector;
use Generator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A deterministic Copilot turn (already run and stored) as the same Vercel
 * data stream parts the agent emits: one `data-copilot-blocks` with every
 * card, the text in a single `text-delta`, `finish-step`, the stored
 * message and `finish` — the same closing order as the agent stream.
 */
final class DeterministicCopilotParts
{
    /**
     * @param  array<string, mixed>  $messagePayload
     * @param  bool  $withStart  false when the agent stream already sent `start`
     * @return Generator<array<string, mixed>>
     */
    public static function parts(CopilotTurnCollector $collector, CopilotTurnOutcome $outcome, array $messagePayload, bool $withStart = true): Generator
    {
        $id = 'det_'.Str::ulid();

        if ($withStart) {
            yield ['type' => 'start', 'messageId' => $id];
            yield ['type' => 'start-step'];
        }

        if (($blocks = $collector->blocks()) !== []) {
            yield ['type' => 'data-copilot-blocks', 'data' => [
                'toolCallId' => $id,
                'tool' => 'deterministic',
                'label' => $outcome->intent->label(),
                'blocks' => $blocks,
            ]];
        }

        yield ['type' => 'text-start', 'id' => $id];
        yield ['type' => 'text-delta', 'id' => $id, 'delta' => $outcome->text];
        yield ['type' => 'text-end', 'id' => $id];
        yield ['type' => 'finish-step'];

        // Same closing order as CopilotStreamProtocol: data parts, then finish.
        if ($collector->followupList() !== []) {
            yield ['type' => 'data-copilot-followups', 'data' => ['questions' => $collector->followupList()]];
        }

        yield ['type' => 'data-copilot-message', 'data' => $messagePayload];
        yield self::finishPart($outcome);
    }

    /**
     * The whole stream of a deterministic turn, framed like the protocol.
     *
     * @param  iterable<array<string, mixed>>  $parts
     */
    public static function response(iterable $parts): StreamedResponse
    {
        return response()->stream(function () use ($parts): Generator {
            foreach ($parts as $part) {
                yield 'data: '.json_encode($part, JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
            }

            yield "data: [DONE]\n\n";
        }, 200, [
            'Cache-Control' => 'no-cache, no-transform',
            'Content-Type' => 'text/event-stream',
            'x-vercel-ai-ui-message-stream' => 'v1',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Same shape as VercelDataProtocol::finishPart(); a deterministic turn
     * spends no tokens.
     *
     * @return array<string, mixed>
     */
    private static function finishPart(CopilotTurnOutcome $outcome): array
    {
        return [
            'type' => 'finish',
            'finishReason' => 'stop',
            'messageMetadata' => [
                'usage' => [
                    'inputTokens' => $outcome->usage->inputTokens,
                    'outputTokens' => $outcome->usage->outputTokens,
                    'totalTokens' => $outcome->usage->totalTokens(),
                    'reasoningTokens' => $outcome->usage->reasoningTokens,
                    'cachedInputTokens' => $outcome->usage->cacheReadInputTokens,
                ],
            ],
        ];
    }
}
