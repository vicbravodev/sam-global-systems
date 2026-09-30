<?php

namespace App\Domains\Copilot\Tools\Sdk;

use App\Domains\Copilot\Support\CopilotTurnCollector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Lets the model propose the follow-up questions the UI shows as chips. It
 * reads no data, so it needs no permission and is always offered.
 */
final class SuggestFollowupsTool implements Tool
{
    public function __construct(private readonly CopilotTurnCollector $collector) {}

    public function name(): string
    {
        return 'suggest_followups';
    }

    public function description(): Stringable|string
    {
        return 'Llama esto al final con 2 o 3 preguntas cortas que el gerente probablemente haría después, basadas en lo que encontraste.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'questions' => $schema->array()->items($schema->string()->max(80))->min(2)->max(3)->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $questions = collect((array) ($request['questions'] ?? []))
            ->filter(fn ($q) => is_string($q) && trim($q) !== '')
            ->map(fn (string $q) => mb_substr(trim($q), 0, 80))
            ->take(3)
            ->values()
            ->all();

        $this->collector->followups($questions);

        return 'ok';
    }
}
