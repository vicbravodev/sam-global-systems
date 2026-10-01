<?php

namespace App\Domains\Copilot\Support;

use App\Domains\Copilot\Data\CopilotToolResult;
use App\Domains\Copilot\Enums\CopilotIntent;
use App\Domains\Copilot\Tools\Sdk\CopilotToolDefinition;
use Closure;

/**
 * Everything the tools produced during one agent turn: the cards the UI
 * renders, the cited records, the tool trail and the follow-up questions.
 * The model only sees the compact JSON each tool returns; this keeps the
 * full result for the UI and for the persisted message.
 */
final class CopilotTurnCollector
{
    /**
     * @var list<array{toolCallId: string, tool: string, result: CopilotToolResult, status: string, durationMs: int, assetId: int|null}>
     */
    private array $entries = [];

    /**
     * @var list<string>
     */
    private array $followups = [];

    /**
     * @var (Closure(array{toolCallId: string, tool: string, label: string, blocks: list<array<string, mixed>>}): void)|null
     */
    private ?Closure $onBlocks = null;

    /**
     * @param  Closure(array{toolCallId: string, tool: string, label: string, blocks: list<array<string, mixed>>}): void  $listener
     */
    public function onBlocks(Closure $listener): void
    {
        $this->onBlocks = $listener;
    }

    /**
     * @param  'ok'|'denied'|'error'  $status
     */
    public function record(string $toolCallId, string $tool, CopilotToolResult $result, string $status, int $durationMs, ?int $assetId): void
    {
        $this->entries[] = compact('toolCallId', 'tool', 'result', 'status', 'durationMs', 'assetId');

        if ($result->blocks !== [] && $this->onBlocks !== null) {
            ($this->onBlocks)(['toolCallId' => $toolCallId, 'tool' => $tool, 'label' => $result->label, 'blocks' => $result->blocks]);
        }
    }

    /**
     * @param  list<string>  $questions
     */
    public function followups(array $questions): void
    {
        $this->followups = array_slice($questions, 0, 3);
    }

    public function hasResults(): bool
    {
        return $this->entries !== [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function blocks(): array
    {
        return array_merge([], ...array_map(fn (array $e) => $e['result']->blocks, $this->entries));
    }

    /**
     * @return list<array{kind: string, id: int, label: string, href: string}>
     */
    public function sources(): array
    {
        $sources = [];

        foreach ($this->entries as $entry) {
            foreach ($entry['result']->sources as $source) {
                $sources[$source['kind'].':'.$source['id']] = $source;
            }
        }

        return array_values(array_slice($sources, 0, 12));
    }

    /**
     * @return list<array{tool: string, label: string, status: string, durationMs: int}>
     */
    public function tools(): array
    {
        return array_map(fn (array $e) => [
            'tool' => $e['tool'],
            'label' => $e['result']->label,
            'status' => $e['status'],
            'durationMs' => $e['durationMs'],
        ], $this->entries);
    }

    /**
     * Grounded sentences of every tool that answered (ok or denied), in the
     * order they ran: the text of last resort when the model wrote none.
     *
     * @return list<string>
     */
    public function highlights(): array
    {
        return array_merge([], ...array_map(
            fn (array $e) => $e['result']->highlights,
            array_filter($this->entries, fn (array $e) => $e['status'] !== 'error'),
        ));
    }

    /**
     * Compact memory of what was looked up, fed back as history next turn.
     */
    public function factsDigest(): string
    {
        $parts = array_map(
            fn (array $e) => $e['tool'].': '.implode(' ', $e['result']->highlights),
            array_filter($this->entries, fn (array $e) => $e['status'] === 'ok'),
        );

        return mb_substr(implode(' | ', $parts), 0, 1500);
    }

    /**
     * @return list<string>
     */
    public function followupList(): array
    {
        return $this->followups;
    }

    public function primaryIntent(): CopilotIntent
    {
        foreach ($this->entries as $entry) {
            if ($intent = CopilotToolDefinition::intentFor($entry['tool'])) {
                return $intent;
            }
        }

        return CopilotIntent::General;
    }

    public function lastAssetId(): ?int
    {
        $ids = array_values(array_filter(array_column($this->entries, 'assetId')));

        return $ids === [] ? null : $ids[count($ids) - 1];
    }
}
