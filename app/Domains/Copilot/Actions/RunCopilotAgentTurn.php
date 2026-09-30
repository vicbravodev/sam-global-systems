<?php

namespace App\Domains\Copilot\Actions;

use App\Domains\Assets\Models\Asset;
use App\Domains\Copilot\Data\CopilotTurn;
use App\Domains\Copilot\Data\CopilotTurnOutcome;
use App\Domains\Copilot\Enums\CopilotIntent;
use App\Domains\Copilot\Support\CopilotToolbox;
use App\Infrastructure\AI\Agents\CopilotAgent;
use App\Infrastructure\AI\Middleware\CopilotStepGuard;

/**
 * Answers a Copilot turn with the tool-calling agent: the tools see only the
 * turn's tenant scope and fill the collector; the outcome carries the usage
 * summed over every step.
 */
class RunCopilotAgentTurn
{
    public function __construct(private readonly CopilotToolbox $toolbox) {}

    /**
     * Same rule as the event evaluator: the agent only runs when the default
     * provider has a key; otherwise the turn is answered deterministically.
     */
    public static function available(): bool
    {
        $key = config('ai.providers.'.config('ai.default').'.key');

        return is_string($key) && trim($key) !== '';
    }

    public function agentFor(CopilotTurn $turn): CopilotAgent
    {
        return new CopilotAgent(
            $turn->scope,
            $turn->history,
            $this->toolbox->for($turn->scope, $turn->collector),
            new CopilotStepGuard((int) config('ai.copilot.max_turn_tokens', 60000)),
        );
    }

    public function execute(CopilotTurn $turn): CopilotTurnOutcome
    {
        $response = $this->agentFor($turn)->prompt($this->prompt($turn));

        return new CopilotTurnOutcome(
            mode: 'agent',
            text: trim($response->text),
            model: $response->meta->model,
            usage: $response->usage,
            steps: $response->steps->count(),
            intent: $turn->collector->primaryIntent(),
            partial: false,
            firstTokenMs: null,
        );
    }

    /**
     * The question plus the UI pills (pinned unit / chosen template) as a
     * hint line the model can use.
     */
    public function prompt(CopilotTurn $turn): string
    {
        $hints = [];

        if ($assetId = $turn->hints['asset_id'] ?? $turn->previousAssetId) {
            $code = Asset::query()->where('team_id', $turn->scope->teamId)->whereKey($assetId)->value('code');
            $hints[] = $code ? "Unidad en contexto: {$code}." : null;
        }

        if (! empty($turn->hints['intent']) && ($intent = CopilotIntent::tryFrom((string) $turn->hints['intent']))) {
            $hints[] = "El usuario eligió: {$intent->label()}.";
        }

        return trim($turn->question->content."\n\n".implode(' ', array_filter($hints)));
    }
}
