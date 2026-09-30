<?php

namespace App\Domains\Copilot\Streaming;

use App\Domains\Copilot\Support\CopilotAnswerText;
use App\Domains\Copilot\Support\CopilotTurnCollector;
use App\Support\SystemLog;
use Closure;
use Generator;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Ai\Streaming\Protocols\VercelDataProtocol;
use Throwable;

/**
 * The Vercel data stream protocol with the Copilot data parts on top:
 *
 * - `data-copilot-blocks` right after the tool that produced the cards;
 * - `data-copilot-followups` and `data-copilot-message` (the stored turn)
 *   just before `finish`. The turn's `then()` runs when the agent stream is
 *   exhausted, which is before the base protocol yields `finish-step` and
 *   `finish`, so the stored message is always there by then;
 * - `tool-output-available` carries `{ok: true}` only: the facts go to the
 *   model, never to the browser; `tool-output-error` a fixed Spanish text;
 * - a provider failure before the first text delta answers the turn
 *   deterministically in the same stream (`$fallback`); after it, the
 *   stored partial answer goes out and then the masked Spanish error;
 * - a browser that left stops the stream and `$onDisconnect` stores what
 *   was sent as partial.
 */
final class CopilotStreamProtocol extends VercelDataProtocol
{
    public const ERROR_TEXT = CopilotAnswerText::EMPTY;

    public const TOOL_ERROR_TEXT = 'No pude consultar esos datos.';

    /**
     * @param  Closure(Throwable, bool): iterable<array<string, mixed>>  $fallback  receives the error and whether it must open the stream (`start`)
     * @param  Closure(): void  $onDisconnect
     */
    public function __construct(
        private readonly CopilotStreamState $state,
        private readonly CopilotTurnCollector $collector,
        private readonly Closure $fallback,
        private readonly Closure $onDisconnect,
        private readonly int $startedAt,
    ) {
        parent::__construct();

        // Runs inside the tool call: it must never throw, only queue the part.
        $collector->onBlocks(function (array $payload): void {
            $this->state->pending[] = ['type' => 'data-copilot-blocks', 'data' => $payload];
        });
    }

    /**
     * {@inheritdoc}
     */
    protected function parts(StreamableAgentResponse $response): Generator
    {
        $closed = false;
        $newStep = false;

        try {
            foreach (parent::parts($response) as $part) {
                if ($this->state->aborted()) {
                    ($this->onDisconnect)();

                    return;
                }

                // A provider error event is always followed by an exception:
                // its raw text never reaches the browser, the catch decides.
                if ($part['type'] === 'error') {
                    $this->errored = false;

                    continue;
                }

                if ($part['type'] === 'finish') {
                    yield from $this->closing();
                    $closed = true;
                }

                if ($part['type'] === 'start-step') {
                    $newStep = true;
                }

                if ($part['type'] === 'text-delta') {
                    $this->trackText((string) $part['delta'], $newStep);
                    $newStep = false;
                }

                yield $part;
                yield from $this->drain();
            }

            if (! $closed) {
                yield from $this->closing();
            }
        } catch (Throwable $e) {
            // After the first text, or once the turn started storing (a store
            // that failed halfway must never fall back and store again).
            if ($this->state->textStarted || $this->state->persisted) {
                // A partial answer stored by the turn's catch() goes out
                // first; then the base masks the error and terminates.
                if ($this->state->messagePayload !== null) {
                    yield ['type' => 'data-copilot-message', 'data' => $this->state->messagePayload];
                }

                throw $e;
            }

            // Cards of the abandoned agent run never reach the browser.
            $this->state->pending = [];

            yield from ($this->fallback)($e, ! $this->started);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function toolResultPart(ToolResult $event): array
    {
        $part = parent::toolResultPart($event);

        return match ($part['type']) {
            'tool-output-available' => ['type' => $part['type'], 'toolCallId' => $part['toolCallId'], 'output' => ['ok' => true]],
            // The SDK fills errorText from the exception message: never sent.
            'tool-output-error' => [...$part, 'errorText' => self::TOOL_ERROR_TEXT],
            default => $part,
        };
    }

    /**
     * {@inheritdoc}
     */
    protected function maskedErrorParts(): Generator
    {
        yield from $this->yieldPart(['type' => 'error', 'errorText' => self::ERROR_TEXT]);
    }

    /**
     * {@inheritdoc}
     */
    protected function headers(): array
    {
        return [...parent::headers(), 'X-Accel-Buffering' => 'no'];
    }

    private function trackText(string $delta, bool $newStep): void
    {
        if (! $this->state->textStarted) {
            $this->state->textStarted = true;
            $this->state->firstTextMs = SystemLog::elapsedMs($this->startedAt);
        }

        if ($newStep && $this->state->text !== '') {
            $this->state->text .= "\n\n";
        }

        $this->state->text .= $delta;
    }

    /**
     * @return Generator<array<string, mixed>>
     */
    private function drain(): Generator
    {
        while ($this->state->pending !== []) {
            yield array_shift($this->state->pending);
        }
    }

    /**
     * @return Generator<array<string, mixed>>
     */
    private function closing(): Generator
    {
        yield from $this->drain();

        if ($this->collector->followupList() !== []) {
            yield ['type' => 'data-copilot-followups', 'data' => ['questions' => $this->collector->followupList()]];
        }

        if ($this->state->messagePayload !== null) {
            yield ['type' => 'data-copilot-message', 'data' => $this->state->messagePayload];
        }
    }
}
