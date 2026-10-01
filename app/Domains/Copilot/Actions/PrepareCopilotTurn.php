<?php

namespace App\Domains\Copilot\Actions;

use App\Domains\Copilot\Data\CopilotTurn;
use App\Domains\Copilot\Data\CopilotTurnScope;
use App\Domains\Copilot\Enums\CopilotMessageRole;
use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Domains\Copilot\Support\CopilotHistory;
use App\Domains\Copilot\Support\CopilotTurnCollector;
use App\Models\Team;
use App\Models\User;
use App\Support\SystemLog;
use Illuminate\Support\Str;

/**
 * First stage of a Copilot turn: checks the conversation belongs to the
 * user, stores the question and gathers the history, the tenant scope and
 * an empty collector. Callers run it inside `TenantContext::for($team->id)`.
 */
class PrepareCopilotTurn
{
    public function __construct(private readonly CopilotHistory $history) {}

    /**
     * @param  list<string>  $permissions
     * @param  array{asset_id?: int|null, intent?: string|null}  $hints
     */
    public function execute(
        Team $team,
        User $user,
        array $permissions,
        string $content,
        ?CopilotConversation $conversation,
        array $hints,
        string $channel,
    ): CopilotTurn {
        abort_if($conversation !== null && ($conversation->team_id !== $team->id || $conversation->user_id !== $user->id), 404);

        $startedAt = hrtime(true);

        ['history' => $history, 'previousAssetId' => $previousAssetId] = $conversation !== null
            ? $this->history->forConversation($conversation)
            : ['history' => [], 'previousAssetId' => null];

        $conversation ??= CopilotConversation::query()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'title' => Str::limit(trim($content), 80),
            'last_message_at' => now(),
        ]);

        $contextHints = array_filter($hints, fn ($value) => $value !== null);

        $question = CopilotMessage::query()->create([
            'team_id' => $team->id,
            'copilot_conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => CopilotMessageRole::User,
            'content' => $content,
            'channel' => $channel,
            'context_json' => $contextHints !== [] ? $contextHints : null,
        ]);

        SystemLog::ok('copilot.turn.started', [
            'team_id' => $team->id,
            'conversation_id' => $conversation->id,
            'channel' => $channel,
            'mode' => RunCopilotAgentTurn::available() ? 'agent' : 'deterministic',
            'question_length' => mb_strlen($content),
            'history_turns' => count($history),
        ], debug: true);

        return new CopilotTurn(
            team: $team,
            user: $user,
            conversation: $conversation,
            question: $question,
            history: $history,
            previousAssetId: $previousAssetId,
            scope: CopilotTurnScope::fromTeam($team, $permissions, $user->isSuperAdmin()),
            collector: new CopilotTurnCollector,
            hints: $hints,
            channel: $channel,
            startedAt: $startedAt,
        );
    }
}
