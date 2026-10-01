<?php

namespace App\Domains\Copilot\Actions;

use App\Domains\Copilot\Data\CopilotTurn;
use App\Domains\Copilot\Data\CopilotTurnOutcome;
use App\Domains\Copilot\Support\TemplateCopilotNarrator;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * Answers a Copilot turn without a language model: the intent router picks
 * the tools, the template stitches their grounded highlights, and the
 * results go through the same collector as the agent's. No tokens.
 */
class RunDeterministicCopilotTurn
{
    public function __construct(
        private readonly AnswerCopilotQuestion $answer,
        private readonly TemplateCopilotNarrator $template,
    ) {}

    public function execute(CopilotTurn $turn): CopilotTurnOutcome
    {
        $question = $turn->question->content;

        $answer = $this->answer->execute(
            teamId: $turn->scope->teamId,
            teamSlug: $turn->scope->teamSlug,
            permissions: $turn->scope->permissions,
            isSuperAdmin: $turn->scope->isSuperAdmin,
            question: $question,
            hints: $turn->hints,
            previousAssetId: $turn->previousAssetId,
        );

        $assetId = isset($answer->resolvedContext['asset_id']) ? (int) $answer->resolvedContext['asset_id'] : null;

        foreach ($answer->results as $i => $result) {
            $turn->collector->record("det_{$i}", $result->tool, $result, $result->denied ? 'denied' : 'ok', 0, $assetId);
        }

        return new CopilotTurnOutcome(
            mode: 'deterministic',
            text: $this->template->narrate($question, $answer)->text,
            model: null,
            usage: new TextUsage,
            steps: 0,
            intent: $answer->intent,
            partial: false,
            firstTextMs: null,
        );
    }
}
