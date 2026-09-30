<?php

namespace App\Infrastructure\AI\Agents;

use App\Contracts\AI\CopilotNarrator;
use App\Domains\AI\Support\ModelPricing;
use App\Domains\Copilot\Data\CopilotAnswer;
use App\Domains\Copilot\Data\CopilotNarration;
use App\Domains\Copilot\Data\CopilotTurnScope;
use App\Domains\Copilot\Support\TemplateCopilotNarrator;
use App\Infrastructure\AI\Middleware\CopilotStepGuard;
use App\Support\SystemLog;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Production narrator backed by the Laravel AI SDK. When the provider fails
 * the operator still gets the grounded template answer (and no tokens are
 * billed for the failed call).
 */
class SdkCopilotNarrator implements CopilotNarrator
{
    public function __construct(
        private readonly ModelPricing $pricing,
        private readonly TemplateCopilotNarrator $fallback,
    ) {}

    public function narrate(string $question, CopilotAnswer $answer, array $history = []): CopilotNarration
    {
        $payload = json_encode([
            'question' => $question,
            'intent' => $answer->intent->value,
            'intent_label' => $answer->intent->label(),
            'facts' => $answer->facts(),
            'highlights' => $answer->highlights(),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        try {
            $response = $this->agent($history)->prompt($payload);
        } catch (Throwable $exception) {
            SystemLog::degraded('copilot.narration.fallback', reason: 'agent_error', error: $exception);

            return $this->fallback->narrate($question, $answer, $history);
        }

        $model = $response->meta?->model;
        $input = (int) $response->usage->inputTokens;
        $output = (int) $response->usage->outputTokens;
        $text = trim($response->text);

        return new CopilotNarration(
            text: $text !== '' ? $text : $this->fallback->narrate($question, $answer, $history)->text,
            model: $model,
            inputTokens: $input,
            outputTokens: $output,
            costEstimate: $this->pricing->estimateCost($model, $input, $output),
        );
    }

    /**
     * Tool-less construction: this legacy narrator only phrases facts it is
     * handed (removed when the agent orchestrates the turn).
     *
     * @param  list<array{role: string, content: string}>  $history
     */
    private function agent(array $history): CopilotAgent
    {
        $scope = new CopilotTurnScope(0, '', [], false, (string) config('app.timezone'), CarbonImmutable::now());

        return new CopilotAgent($scope, $history, [], new CopilotStepGuard((int) config('ai.copilot.max_turn_tokens', 60000)));
    }
}
