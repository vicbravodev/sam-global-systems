<?php

namespace App\Domains\Copilot\Actions;

use App\Domains\Copilot\Data\CopilotTurn;
use App\Domains\Copilot\Data\CopilotTurnOutcome;
use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Domains\Copilot\Support\CopilotTurnCollector;
use App\Models\Team;
use App\Models\User;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Throwable;

/**
 * One full Copilot turn over JSON: prepare it (question, history, scope),
 * answer it with the tool-calling agent, or deterministically when there is
 * no provider key or the provider fails, then store, meter and audit it.
 */
class SendCopilotMessage
{
    public function __construct(
        private readonly PrepareCopilotTurn $prepare,
        private readonly RunCopilotAgentTurn $agent,
        private readonly RunDeterministicCopilotTurn $deterministic,
        private readonly FinishCopilotTurn $finish,
        private readonly RecordCopilotUsage $recordUsage,
    ) {}

    /**
     * @param  list<string>  $permissions
     * @param  array{asset_id?: int|null, intent?: string|null}  $hints
     * @return array{conversation: CopilotConversation, question: CopilotMessage, answer: CopilotMessage}
     */
    public function execute(
        Team $team,
        User $user,
        array $permissions,
        string $content,
        ?CopilotConversation $conversation = null,
        array $hints = [],
        string $channel = 'page',
    ): array {
        return TenantContext::for($team->id, function () use ($team, $user, $permissions, $content, $conversation, $hints, $channel): array {
            $turn = $this->prepare->execute($team, $user, $permissions, $content, $conversation, $hints, $channel);

            $reply = $this->finish->execute($turn, $this->run($turn));

            return [
                'conversation' => $turn->conversation->refresh(),
                'question' => $turn->question,
                'answer' => $reply,
            ];
        });
    }

    private function run(CopilotTurn $turn): CopilotTurnOutcome
    {
        if (! RunCopilotAgentTurn::available()) {
            SystemLog::skipped('copilot.turn.fallback', 'no_provider_key', ['team_id' => $turn->team->id], debug: true);

            return $this->deterministic->execute($turn);
        }

        try {
            return $this->agent->execute($turn);
        } catch (Throwable $e) {
            SystemLog::degraded('copilot.turn.fallback', 'agent_error_before_output', ['team_id' => $turn->team->id], calc: ['tokens_so_far' => $turn->spent->tokens()], error: $e);

            // What the abandoned agent already spent is billed apart.
            $this->recordUsage->executeAbandoned($turn);

            // Start over: nothing a half-run agent collected reaches the answer.
            $turn->collector = new CopilotTurnCollector;

            return $this->deterministic->execute($turn);
        }
    }
}
