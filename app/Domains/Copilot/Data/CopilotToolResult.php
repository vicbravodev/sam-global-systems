<?php

namespace App\Domains\Copilot\Data;

/**
 * Output of one data tool: the cards the UI renders, the records it cited
 * and a compact set of facts the narrator (template or LLM) phrases.
 */
final readonly class CopilotToolResult
{
    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array{kind: string, id: int, label: string, href: string}>  $sources
     * @param  array<string, mixed>  $facts
     * @param  list<string>  $highlights  Short Spanish sentences, already grounded on data.
     * @param  bool  $denied  The user's role may not read what this tool covers.
     */
    public function __construct(
        public string $tool,
        public string $label,
        public array $blocks = [],
        public array $sources = [],
        public array $facts = [],
        public array $highlights = [],
        public bool $denied = false,
    ) {}

    public static function denied(string $tool, string $label, string $what): self
    {
        return new self(
            tool: $tool,
            label: $label,
            blocks: [[
                'type' => 'notice',
                'tone' => 'warn',
                'text' => "Tu rol no tiene permiso para consultar {$what}. Pídele acceso a un administrador del tenant.",
            ]],
            highlights: ["No tienes permiso para consultar {$what}."],
            denied: true,
        );
    }
}
