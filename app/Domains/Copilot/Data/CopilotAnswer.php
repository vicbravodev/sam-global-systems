<?php

namespace App\Domains\Copilot\Data;

use App\Domains\Copilot\Enums\CopilotIntent;

/**
 * Grounded answer before narration: the resolved intent, the tools that ran
 * and everything they produced.
 */
final readonly class CopilotAnswer
{
    /**
     * @param  list<CopilotToolResult>  $results
     * @param  array<string, mixed>  $resolvedContext
     */
    public function __construct(
        public CopilotIntent $intent,
        public array $results,
        public array $resolvedContext,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function blocks(): array
    {
        return array_merge(...array_map(fn (CopilotToolResult $r) => $r->blocks, $this->results ?: [new CopilotToolResult('none', 'none')]));
    }

    /**
     * @return list<array{tool: string, label: string}>
     */
    public function tools(): array
    {
        return array_map(fn (CopilotToolResult $r) => ['tool' => $r->tool, 'label' => $r->label], $this->results);
    }

    /**
     * @return list<array{kind: string, id: int, label: string, href: string}>
     */
    public function sources(): array
    {
        $sources = [];

        foreach ($this->results as $result) {
            foreach ($result->sources as $source) {
                $sources[$source['kind'].':'.$source['id']] = $source;
            }
        }

        return array_values(array_slice($sources, 0, 12));
    }

    /**
     * @return list<string>
     */
    public function highlights(): array
    {
        return array_merge(...array_map(fn (CopilotToolResult $r) => $r->highlights, $this->results ?: [new CopilotToolResult('none', 'none')]));
    }

    /**
     * @return array<string, mixed>
     */
    public function facts(): array
    {
        $facts = [];

        foreach ($this->results as $result) {
            $facts[$result->tool] = $result->facts;
        }

        return $facts;
    }
}
