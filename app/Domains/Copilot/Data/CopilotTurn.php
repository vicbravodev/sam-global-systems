<?php

namespace App\Domains\Copilot\Data;

use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Domains\Copilot\Support\CopilotTurnCollector;
use App\Domains\Copilot\Support\CopilotTurnUsage;
use App\Models\Team;
use App\Models\User;

/**
 * One Copilot turn in flight: the stored question, the history the model
 * sees, the server-built scope and the collector of what the tools produced.
 * Shared by the JSON and the streaming endpoints.
 *
 * `$collector` stays mutable: a fallback to the deterministic path starts
 * over with an empty one so no half-run agent tool leaks into the answer.
 * `$spent` adds up the tokens of every agent step as it completes, so a
 * turn that fails or is cut short still bills what it spent.
 */
final class CopilotTurn
{
    /**
     * @param  list<array{role: string, content: string}>  $history
     * @param  array{asset_id?: int|null, intent?: string|null}  $hints
     * @param  list<string>  $askedQuestions  earlier questions of the conversation
     */
    public function __construct(
        public readonly Team $team,
        public readonly User $user,
        public readonly CopilotConversation $conversation,
        public readonly CopilotMessage $question,
        public readonly array $history,
        public readonly ?int $previousAssetId,
        public readonly CopilotTurnScope $scope,
        public CopilotTurnCollector $collector,
        public readonly array $hints,
        public readonly string $channel,
        public readonly int $startedAt,
        public readonly CopilotTurnUsage $spent = new CopilotTurnUsage,
        public readonly array $askedQuestions = [],
    ) {}
}
